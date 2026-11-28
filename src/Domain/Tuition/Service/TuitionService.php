<?php

declare(strict_types=1);

namespace Domain\Tuition\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Service\FinancialCommandPolicy;
use Domain\Finance\Service\LedgerControl;
use Domain\Finance\Service\TermFeeBilling;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\RegistrationPrice;
use Domain\Person\Service\ProfileLedger;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventEvidence;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\TuitionBill;
use Domain\Tuition\ValueObject\InvoiceCharge;
use Domain\Tuition\ValueObject\TuitionSchema;

final readonly class TuitionService
{
    public function __construct(
        private TuitionLedger $ledger,
        private TuitionBilling $pricing,
        private LedgerControl $control,
        private AccountRepository $accounts,
        private TermFeeBilling $fees,
        private AdmissionsRepository $admissions,
        private RegistrationRepository $registrations,
        private ProfileLedger $profiles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
        private HistoricalEventEvidence $evidence,
        private FinancialCommandPolicy $commands,
    ) {
    }

    private function staff(bool $write = false, bool $setup = false): EntityId
    {
        $actor = $this->access->requirePermission("sis:finance:read");
        $this->commands->assertActor($actor, "sis:student:read");
        if ($write) {
            $this->commands->assertActor($actor, "sis:finance:post");
        }
        if ($setup) {
            $this->commands->assertActor($actor, "sis:finance:ledger");
        }
        return $actor;
    }

    private function sameActor(
        EntityId $actor,
        bool $write = false,
        bool $setup = false,
    ): void {
        if ($this->staff($write, $setup)->value !== $actor->value) {
            throw new AccessDeniedException();
        }
    }

    public function schedules(?EntityId $after = null): array
    {
        $actor = $this->staff();
        $rows = $this->ledger->records->schedules($after);
        $items = [];
        foreach (array_slice($rows, 0, 20) as $r) {
            $items[] =
            $this->ledger->setting("schedules", new EntityId($r["id"])) ??
            throw new CorruptHistoryException();
        }
        $this->sameActor($actor);
        return [
            "items" => $items,
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function schedule(EntityId $id): array
    {
        $actor = $this->staff();
        $result =
        $this->ledger->setting("schedules", $id) ??
        throw new RecordNotFoundException("Unknown schedule.");
        $this->sameActor($actor);
        return $result;
    }

    public function assignment(string $scope, EntityId $id): array
    {
        $actor = $this->staff();
        TuitionSchema::assignment([
            "scope" => $scope,
            "scope_id" => $id->value,
            "schedule_id" => null,
        ]);
        $label = $this->ledger->records->scopeLabel($scope, $id);
        $record = $this->ledger->setting(
            "assignments",
            TuitionLedger::assignmentId($scope, $id),
        );
        $this->sameActor($actor);
        return [
            "scope" => $scope,
            "scope_id" => $id->value,
            "label" => $label,
            "record" => $record,
        ];
    }

    private function income(EntityId $id, string $currency): array
    {
        $book =
        $this->control->book($currency) ??
        throw new \DomainException("Publish accounting before tuition.");
        $r = $this->control->records->account($id->value);
        if (
            $r["currency"] !== $currency ||
            $r["kind"] !== "income" ||
            $r["id"] === $book["definition"]["receivable"]["id"]
        ) {
            throw new \DomainException(
                "Choose a classified income account in the schedule currency.",
            );
        }
        return array_intersect_key($r, array_flip(["id", "code", "title"]));
    }

    public function reviewSchedule(
        ?EntityId $id,
        int $version,
        string $name,
        string $currency,
        EntityId $income,
        array $brackets,
        string $reason,
    ): array {
        $actor = $this->staff(true, true);
        $id ??= EntityId::generate();
        $current = $this->ledger->setting("schedules", $id);
        $this->sameVersion($version, $current["version"] ?? 0);
        $d = [
            "name" => trim($name),
            "currency" => $currency,
            "income" => $this->income($income, $currency),
            "brackets" => $brackets,
        ];
        TuitionSchema::definition($d);
        $this->sameActor($actor, true, true);
        return $this->review("schedule", $id, $version, $d, $actor, $reason);
    }

    public function reviewAssignment(
        string $scope,
        EntityId $target,
        ?EntityId $schedule,
        int $version,
        string $reason,
    ): array {
        $actor = $this->staff(true, true);
        $d = [
            "scope" => $scope,
            "scope_id" => $target->value,
            "schedule_id" => $schedule?->value,
        ];
        TuitionSchema::assignment($d);
        $this->ledger->records->scopeLabel($scope, $target);
        if ($schedule !== null) {
            $source =
            $this->ledger->setting("schedules", $schedule) ??
            throw new RecordNotFoundException();
        }
        $id = TuitionLedger::assignmentId($scope, $target);
        $this->sameVersion(
            $version,
            $this->ledger->setting("assignments", $id)["version"] ?? 0,
        );
        $this->sameActor($actor, true, true);
        return $this->review("assignment", $id, $version, $d, $actor, $reason, [
            "schedule_event_id" => $source["event_id"] ?? null,
        ]);
    }

    public function bills(
        bool $pending = true,
        string $mode = "staff",
        ?EntityId $after = null,
    ): array {
        $actor =
        $mode === "owner"
        ? $this->access->authenticatedUserId()
        : $this->staff();
        if (!in_array($mode, ["owner", "staff"], true)) {
            throw new AccessDeniedException();
        }
        $rows = $this->ledger->records->bills(
            $pending,
            $mode === "owner" ? $actor : null,
            $after,
        );
        $items = [];
        $budget = new HistoryReadBudget(
            maximumEvents: 2000,
            maximumBytes: 8388608,
        );
        foreach (array_slice($rows, 0, 20) as $r) {
            $items[] = $this->bill(
                new EntityId($r["id"]),
                $mode,
                budget: $budget,
            );
        }
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return [
            "items" => $items,
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    private function billScope(
        EntityId $id,
        string $mode,
        ?EntityId $expected = null,
    ): array {
        $actor =
        $mode === "owner"
        ? $this->access->authenticatedUserId()
        : $this->staff();
        if (
            !in_array($mode, ["owner", "staff"], true) ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        $r =
        $this->ledger->records->find("bills", $id) ??
        throw new RecordNotFoundException();
        $student = $this->admissions->student(new EntityId($r["student_id"]));
        $person = $this->admissions->person($student->personId);
        if (
            $mode === "owner" &&
            $person->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return [$actor, $student, $person, $r];
    }

    public function bill(
        EntityId $id,
        string $mode = "staff",
        ?EntityId $invoiceAfter = null,
        ?HistoryReadBudget $budget = null,
    ): array {
        [$actor, $student, $person, $r] = $this->billScope($id, $mode);
        $root = $this->ledger->bill($id, $budget);
        $state = $root->state();
        $pending = $root->pending();
        $lines =
        $mode === "staff"
        ? $pending["lines"]
        : array_map(self::ownerLine(...), $pending["lines"]);
        $invoices = [];
        $issued = $state["invoices"];
        krsort($issued, SORT_STRING);
        if ($invoiceAfter !== null) {
            $issued = array_filter(
                $issued,
                static fn(array $r): bool => $r["invoice_id"] <
                    $invoiceAfter->value,
            );
        }
        foreach (array_slice($issued, 0, 20, true) as $invoice) {
            $invoices[] = [
                "id" => $invoice["invoice_id"],
                "reference" => $invoice["reference"],
                "issued_at" => $invoice["issued_at"],
                "due_on" => $invoice["due_on"],
                "total_minor" => array_sum(
                    array_column($invoice["lines"], "minor"),
                ),
            ];
        }
        $this->billScope($id, $mode, $actor);
        return [
            "id" => $id->value,
            "student_id" => $student->id->value,
            "term_id" => $r["term_id"],
            "student_label" =>
                $person->institutionalId->value .
                " · " .
                $person->givenName .
                " " .
                $person->familyName,
            "term_label" => $this->ledger->records->termLabel(
                new EntityId($r["term_id"]),
            ),
            "currency" => $r["currency"],
            "version" => $root->playhead(),
            "lines" => $lines,
            "credits_milli" => $pending["credits_milli"],
            "pending_minor" => $pending["total_minor"],
            "adjustment_minor" => $pending["adjustment_minor"],
            "schedule_name" => $state["snapshot"]["definition"]["name"],
            "can_reprice" => $state["invoices"] === [],
            "invoices" => $invoices,
            "next_invoice_after" =>
                count($issued) > 20 ? $invoices[19]["id"] : null,
            "default_due_on" => $this->clock
                ->now()
                ->modify("+30 days")
                ->format("Y-m-d"),
        ];
    }

    private static function ownerLine(array $line): array
    {
        return array_intersect_key(
            $line,
            array_flip(["key", "name", "minor", "quoted_minor"]),
        );
    }

    private function lockedBill(EntityId $id): array
    {
        [$actor, $student, $person, $row] = $this->billScope($id, "staff");
        $this->registrations->lockPerson($person->id);
        $this->registrations->lockStudent($student->id);
        $this->control->records->lock();
        $this->sameActor($actor, true);
        return [
            $actor,
            $student,
            $this->admissions->person($person->id),
            $row,
            $this->ledger->bill($id),
        ];
    }

    private function account(EntityId $student, EntityId $term): ?StudentAccount
    {
        $row = $this->ledger->records->account($student, $term);
        if ($row === null) {
            return null;
        }
        $account = $this->accounts->get(new AccountId($row["id"]));
        if (
            $account->studentId()->value !== $student->value ||
            $account->termId()->value !== $term->value ||
            $account->balance()->currency !== $row["currency"]
        ) {
            throw new CorruptHistoryException();
        }
        return $account;
    }

    public function reviewInvoice(
        EntityId $id,
        int $version,
        array $edits,
        string $due,
        string $reason,
    ): array {
        $this->staff(true);
        return $this->transactions->run(function () use (
            $id,
            $version,
            $edits,
            $due,
            $reason,
        ): array {
            [$actor, $student, $person, $row, $root] = $this->lockedBill($id);
            $this->sameVersion($version, $root->playhead());
            $date = DateTimeImmutable::createFromFormat("!Y-m-d", $due);
            if (
                $date === false ||
                $date->format("Y-m-d") !== $due ||
                $due < $this->clock->now()->format("Y-m-d")
            ) {
                throw new \DomainException(
                    "Choose an explicit current or future invoice due date.",
                );
            }
            $pending = $root->pending();
            if (
                $pending["lines"] === [] ||
                count($edits) !== count($pending["lines"])
            ) {
                throw new \DomainException(
                    "Review every current pending line.",
                );
            }
            $choices = [];
            foreach ($edits as $edit) {
                LedgerSchema::keys($edit, ["key", "minor"]);
                if (
                    isset($choices[$edit["key"]]) ||
                    !is_int($edit["minor"]) ||
                    $edit["minor"] < 0 ||
                    $edit["minor"] > Money::MAX_MINOR
                ) {
                    throw new \DomainException(
                        "Use one bounded amount for each pending line.",
                    );
                }
                $choices[$edit["key"]] = $edit["minor"];
            }
            $lines = [];
            foreach ($pending["lines"] as $line) {
                $minor =
                    $choices[$line["key"]] ??
                    throw new \DomainException(
                        "The pending selection changed.",
                    );
                $lines[] = array_replace($line, ["minor" => $minor]);
            }
            $total = array_sum(array_column($lines, "minor"));
            if ($total > Money::MAX_MINOR) {
                throw new \DomainException(
                    "The invoice exceeds its amount limit.",
                );
            }
            $account = $this->account(
                $student->id,
                new EntityId($row["term_id"]),
            );
            if (
                $account !== null &&
                ($account->balance()->currency !== $row["currency"] ||
                    $account->balance()->minor > Money::MAX_MINOR - $total)
            ) {
                throw new \DomainException(
                    "Resolve the current account currency or balance before invoicing.",
                );
            }
            $budget = new HistoryReadBudget();
            $recipient = [
                "person_id" => $person->id->value,
                "name" => $person->givenName . " " . $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "core_reference" => $this->profiles->coreReference(
                    $person,
                    $budget,
                ),
            ];
            $invoice = EntityId::generate();
            $d = [
                "invoice_id" => $invoice->value,
                "reference" => "INV-" . $invoice->value,
                "due_on" => $due,
                "account_id" =>
                    $account === null ? null : (string) $account->aggregateId(),
                "lines" => $lines,
                "recipient" => $recipient,
                "currency" => $row["currency"],
            ];
            $this->sameActor($actor, true);
            return $this->review(
                "invoice",
                $id,
                $version,
                $d,
                $actor,
                $reason,
                [
                    "account_position" => $account?->playhead() ?? 0,
                    "snapshot" => LedgerSchema::hash($root->state()),
                ],
            );
        });
    }

    public function reviewReprice(
        EntityId $id,
        int $version,
        string $reason,
    ): array {
        $this->staff(true, true);
        return $this->transactions->run(function () use (
            $id,
            $version,
            $reason,
        ): array {
            [$actor, $student, , $row, $root] = $this->lockedBill($id);
            $this->sameVersion($version, $root->playhead());
            if ($root->state()["invoices"] !== []) {
                throw new \DomainException(
                    "Issued terms retain their original pricing.",
                );
            }
            $term = new EntityId($row["term_id"]);
            $budget = new HistoryReadBudget();
            $rules = $this->fees->rules($term, $budget, [$student->programId]);
            $legacy = $this->fees->quote($student->id, $term, $rules, $budget);
            $snapshot =
                $this->pricing->snapshot($student->id, $term, $legacy) ??
                throw new \DomainException(
                    "Assign the desired current schedule first.",
                );
            if ($snapshot["definition"]["currency"] !== $row["currency"]) {
                throw new \DomainException(
                    "Existing registration fees cannot be reinterpreted in another currency.",
                );
            }
            return $this->review(
                "reprice",
                $id,
                $version,
                ["snapshot" => $snapshot],
                $actor,
                $reason,
            );
        });
    }

    private function review(
        string $action,
        EntityId $id,
        int $version,
        array $d,
        EntityId $actor,
        string $reason,
        array $basis = [],
    ): array {
        $reason = $this->commands->reason($reason);
        $claim = [
            "purpose" => "tuition.review",
            "action" => $action,
            "id" => $id->value,
            "version" => $version,
            "definition" => $d,
            "basis" => $basis,
            "reason" => $reason,
            "actor" => $actor->value,
            "operation" => EntityId::generate()->value,
            "issued" => $this->clock->now()->getTimestamp(),
        ];
        return [
            "action" => $action,
            "id" => $id->value,
            "version" => $version,
            "definition" => $d,
            "reason" => $reason,
            "review_token" => $this->cipher->seal(
                json_encode($claim, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    private function proof(string $token, EntityId $actor): array
    {
        try {
            if (strlen($token) > 262144) {
                throw new \DomainException();
            }
            $p = json_decode(
                $this->cipher->open($token),
                true,
                25,
                JSON_THROW_ON_ERROR,
            );
            if (
                ($p["purpose"] ?? null) !== "tuition.review" ||
                $p["actor"] !== $actor->value ||
                !is_int($p["issued"]) ||
                !is_int($p["version"])
            ) {
                throw new \DomainException();
            }
            return $p;
        } catch (\Throwable) {
            throw new AccessDeniedException(
                "Review this tuition action again.",
            );
        }
    }

    private function fresh(array $p): void
    {
        $age = $this->clock->now()->getTimestamp() - $p["issued"];
        if ($age < 0 || $age >= 1200) {
            throw new ConcurrentModificationException(
                "The tuition review expired. Review again.",
            );
        }
    }

    private function sameVersion(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new ConcurrentModificationException(
                "The tuition source changed. Review again.",
            );
        }
    }

    public function confirm(string $token): array
    {
        $actor = $this->staff(true);
        $p = $this->proof($token, $actor);
        $setup = in_array(
            $p["action"],
            ["schedule", "assignment", "reprice"],
            true,
        );
        $this->sameActor($actor, true, $setup);
        return $this->transactions->run(function () use (
            $p,
            $actor,
            $setup,
        ): array {
            $id = new EntityId($p["id"]);
            if (in_array($p["action"], ["invoice", "reprice"], true)) {
                [, $student, $person, $row, $root] = $this->lockedBill($id);
            } else {
                $this->control->records->lock();
            }
            $op = new EntityId($p["operation"]);
            $old = $this->evidence->operationEvents($op);
            if ($old !== null) {
                $stored = $old[0];
                $payload = $stored->event->payload();
                if (
                    $stored->context->actor?->value !== $actor->value ||
                    $stored->context->reason !== $p["reason"] ||
                    (string) $stored->event->aggregateId() !== $p["id"]
                ) {
                    throw new ConcurrentModificationException(
                        "This operation belongs to another tuition decision.",
                    );
                }
                if ($p["action"] === "invoice") {
                    $result =
                        $root->state()["invoices"][
                            $p["definition"]["invoice_id"]
                        ] ?? throw new CorruptHistoryException();
                    $this->checkInvoiceReceipt($p, $result);
                    $this->invoice(new EntityId($result["invoice_id"]));
                } elseif (
                    LedgerSchema::hash(
                        $payload["definition"] ?? $payload["details"],
                    ) !== LedgerSchema::hash($p["definition"])
                ) {
                    throw new CorruptHistoryException();
                }
                $this->sameActor($actor, true, $setup);
                return [
                    "id" =>
                        $p["action"] === "invoice"
                            ? $p["definition"]["invoice_id"]
                            : $id->value,
                    "action" => $p["action"],
                    "bill_id" => $p["action"] === "invoice" ? $id->value : null,
                ];
            }
            $this->fresh($p);
            $this->sameActor($actor, true, $setup);
            $context = $this->commands->context($actor, $op, $p["reason"]);
            $d = $p["definition"];
            if ($p["action"] === "schedule") {
                $current = $this->ledger->setting("schedules", $id);
                $this->sameVersion($p["version"], $current["version"] ?? 0);
                if (
                    LedgerSchema::hash(
                        $this->income(
                            new EntityId($d["income"]["id"]),
                            $d["currency"],
                        ),
                    ) !== LedgerSchema::hash($d["income"])
                ) {
                    throw new ConcurrentModificationException(
                        "The income account changed. Review again.",
                    );
                }
                $this->ledger->saveSetting(
                    "schedules",
                    $id,
                    $d,
                    $p["version"],
                    $context,
                );
            } elseif ($p["action"] === "assignment") {
                $current = $this->ledger->setting("assignments", $id);
                $this->sameVersion($p["version"], $current["version"] ?? 0);
                $this->ledger->records->scopeLabel(
                    $d["scope"],
                    new EntityId($d["scope_id"]),
                );
                $source =
                    $d["schedule_id"] === null
                        ? null
                        : $this->ledger->setting(
                            "schedules",
                            new EntityId($d["schedule_id"]),
                        );
                if (
                    ($source["event_id"] ?? null) !==
                    $p["basis"]["schedule_event_id"]
                ) {
                    throw new ConcurrentModificationException(
                        "The selected schedule changed. Review again.",
                    );
                }
                $this->ledger->saveSetting(
                    "assignments",
                    $id,
                    $d,
                    $p["version"],
                    $context,
                );
            } elseif ($p["action"] === "reprice") {
                $this->sameVersion($p["version"], $root->playhead());
                $term = new EntityId($row["term_id"]);
                $budget = new HistoryReadBudget();
                $rules = $this->fees->rules($term, $budget, [
                    $student->programId,
                ]);
                $current = $this->pricing->snapshot(
                    $student->id,
                    $term,
                    $this->fees->quote($student->id, $term, $rules, $budget),
                );
                if (
                    LedgerSchema::hash($current) !==
                    LedgerSchema::hash($d["snapshot"])
                ) {
                    throw new ConcurrentModificationException(
                        "The current schedule assignment changed. Review again.",
                    );
                }
                $this->ledger->record(
                    $id,
                    $op,
                    "reprice",
                    $d,
                    $p["version"],
                    $context,
                );
            } elseif ($p["action"] === "invoice") {
                $this->sameVersion($p["version"], $root->playhead());
                if ($d["due_on"] < $this->clock->now()->format("Y-m-d")) {
                    throw new ConcurrentModificationException(
                        "Review the invoice due date again.",
                    );
                }
                if (
                    LedgerSchema::hash($root->state()) !==
                    $p["basis"]["snapshot"]
                ) {
                    throw new ConcurrentModificationException(
                        "Pending charges changed. Review again.",
                    );
                }
                $account = $this->account(
                    $student->id,
                    new EntityId($row["term_id"]),
                );
                $this->sameVersion(
                    $p["basis"]["account_position"],
                    $account?->playhead() ?? 0,
                );
                if (
                    ($account === null
                        ? null
                        : (string) $account->aggregateId()) !== $d["account_id"]
                ) {
                    throw new ConcurrentModificationException(
                        "The account changed. Review again.",
                    );
                }
                $core = $this->profiles->coreReference(
                    $person,
                    new HistoryReadBudget(),
                );
                if (
                    LedgerSchema::hash($core) !==
                        LedgerSchema::hash($d["recipient"]["core_reference"]) ||
                    $d["recipient"]["name"] !==
                        $person->givenName . " " . $person->familyName ||
                    $d["recipient"]["institutional_id"] !==
                        $person->institutionalId->value
                ) {
                    throw new ConcurrentModificationException(
                        "The invoice identity changed. Review again.",
                    );
                }
                $total = array_sum(array_column($d["lines"], "minor"));
                if ($account === null && $total > 0) {
                    $account = StudentAccount::open(
                        new AccountId(),
                        $student->id,
                        new EntityId($row["term_id"]),
                        $row["currency"],
                    );
                }
                $d["account_id"] =
                    $account === null ? null : (string) $account->aggregateId();
                $d["issued_at"] = $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s\Z");
                $root = $this->ledger->record(
                    $id,
                    $op,
                    "invoice",
                    $d,
                    $p["version"],
                    $context,
                );
                $events =
                    $this->evidence->operationEvents($op) ??
                    throw new CorruptHistoryException();
                $event = $events[0]->event;
                foreach ($d["lines"] as $line) {
                    if ($line["minor"] === 0) {
                        continue;
                    }
                    $basis = new InvoiceCharge([
                        "bill_id" => $id->value,
                        "invoice_id" => $d["invoice_id"],
                        "event_id" => (string) $event->eventId(),
                        "line" => $line,
                        "reference" => $d["reference"],
                        "currency" => $d["currency"],
                    ]);
                    $account->post(
                        InvoiceCharge::operation($d["invoice_id"], $line),
                        EntryType::Charge,
                        new Money($line["minor"], $d["currency"]),
                        $actor,
                        registrationPrice: $line["price"] === null
                            ? null
                            : RegistrationPrice::fromRecorded($line["price"]),
                        chargeSource: ChargeSource::from($line["source"]),
                        invoiceCharge: $basis,
                    );
                }
                if ($account !== null) {
                    $this->accounts->save($account, $context);
                }
                $this->ledger->records->addInvoice([
                    "id" => $d["invoice_id"],
                    "bill_id" => $id->value,
                    "reference" => $d["reference"],
                    "issued_at" => $d["issued_at"],
                ]);
            } else {
                throw new AccessDeniedException();
            }
            $this->audit->record($actor, "tuition." . $p["action"], $id);
            $this->fresh($p);
            $this->sameActor($actor, true, $setup);
            if (isset($student)) {
                $this->billScope($id, "staff", $actor);
            }
            return [
                "id" =>
                    $p["action"] === "invoice" ? $d["invoice_id"] : $id->value,
                "action" => $p["action"],
                "bill_id" => $p["action"] === "invoice" ? $id->value : null,
            ];
        });
    }

    private function checkInvoiceReceipt(array $p, array $d): void
    {
        foreach (
            [
                "invoice_id",
                "reference",
                "due_on",
                "lines",
                "recipient",
                "currency",
            ] as $key
        ) {
            if (
                LedgerSchema::hash($d[$key]) !==
                LedgerSchema::hash($p["definition"][$key])
            ) {
                throw new CorruptHistoryException();
            }
        }
    }

    public function invoice(EntityId $id, string $mode = "staff"): array
    {
        $reader = $mode === 'owner' ? $this->access->authenticatedUserId() : $this->staff();
        if (!in_array($mode, ['owner', 'staff'], true)) {
            throw new AccessDeniedException();
        }
        $row =
        $this->ledger->records->invoice($id) ??
        throw new RecordNotFoundException();
        $bill = new EntityId($row["bill_id"]);
        [$actor] = $this->billScope($bill, $mode, $reader);
        $root = $this->ledger->bill($bill);
        $d =
        $root->state()["invoices"][$id->value] ??
        throw new CorruptHistoryException();
        if (
            $row["reference"] !== $d["reference"] ||
            $row["issued_at"] !== $d["issued_at"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->billScope($bill, $mode, $actor);
        return [
            "id" => $id->value,
            "bill_id" => $bill->value,
            "reference" => $d["reference"],
            "issued_at" => $d["issued_at"],
            "due_on" => $d["due_on"],
            "currency" => $d["currency"],
            "recipient" => array_intersect_key(
                $d["recipient"],
                array_flip(["name", "institutional_id"]),
            ),
            "term_label" => $this->ledger->records->termLabel(
                new EntityId($root->state()["term_id"]),
            ),
            "account_id" => $d["account_id"],
            "lines" => array_map(self::ownerLine(...), $d["lines"]),
            "total_minor" => array_sum(array_column($d["lines"], "minor")),
        ];
    }
}
