<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Brick\Math\BigInteger;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\AccountingControlIssue;
use Domain\Finance\Exception\AccountingControlException;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\Event\JournalWasPosted;
use Domain\Finance\Event\LedgerBookWasPublished;
use Domain\Finance\Event\LedgerPeriodWasRecorded;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\LedgerBookId;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventEvidence;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class StudentLedgerService
{
    public function __construct(
        private LedgerControl $control,
        private JournalRepository $journals,
        private HistoricalEventEvidence $evidence,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private FinancialCommandPolicy $commands,
        private Clock $clock,
        private MessageCipher $cipher,
    ) {
    }

    private function actor(bool $closing = false): EntityId
    {
        $actor = $this->access->requirePermission("sis:finance:ledger");
        if ($closing) {
            $this->commands->assertActor($actor, "sis:finance:close");
            $this->commands->assertActor($actor, "sis:finance:read");
        }
        return $actor;
    }

    private function fresh(EntityId $actor, bool $closing = false): void
    {
        if ($this->actor($closing)->value !== $actor->value) {
            throw new AccessDeniedException("The accounting actor changed.");
        }
    }

    public function overview(string $currency, ?string $after = null): array
    {
        $actor = $this->actor();
        $book = $this->control->book($currency);
        $periods = [];
        $next = null;
        if ($book !== null) {
            $references = $book["definition"]["periods"];
            usort(
                $references,
                static fn(array $a, array $b): int => strcmp(
                    $a["id"],
                    $b["id"],
                ),
            );
            foreach ($references as $reference) {
                if ($after !== null && $reference["id"] <= $after) {
                    continue;
                }
                if (count($periods) === 20) {
                    $next = $periods[array_key_last($periods)]["id"];
                    break;
                }
                $periods[] = $this->control->period($reference["id"]);
            }
        }
        $this->fresh($actor);
        return [
            "currency" => $currency,
            "book" => $book,
            "periods" => $periods,
            "next_after" => $next,
            "default_timezone" => $this->clock->now()->getTimezone()->getName(),
            'default_date' => $this->clock->now()->setTimezone(new \DateTimeZone(
                $book['definition']['timezone'] ?? $this->clock->now()->getTimezone()->getName()
            ))->format('Y-m-d'),
        ];
    }

    private function account(string $id, string $currency): array
    {
        $row = $this->control->records->account(new EntityId($id)->value);
        if ($row["currency"] !== $currency) {
            throw new DomainException(
                "Every mapped account must use the selected currency.",
            );
        }
        return [
            "id" => $row["id"],
            "code" => $row["code"],
            "title" => $row["title"],
        ];
    }

    public function reviewMapping(
        string $currency,
        string $timezone,
        string $receivableCode,
        string $receivableTitle,
        EntityId $charge,
        EntityId $payment,
        EntityId $refund,
        array $sources,
        array $methods,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->actor();
        new Money(0, $currency);
        $reason = $this->commands->reason($reason);
        $book = $this->control->book($currency);
        if (
            $book === null &&
            $this->control->records->hasEarlierPostings($currency)
        ) {
            throw new AccountingControlException(AccountingControlIssue::OpeningPosition);
        }
        $definition = [
            "currency" => $currency,
            "timezone" => $timezone,
            "receivable" => [
                "id" =>
                    $book["definition"]["receivable"]["id"] ??
                    EntityId::generate()->value,
                "code" => trim($receivableCode),
                "title" => trim($receivableTitle),
            ],
            "charge" => $this->account($charge->value, $currency),
            "payment" => $this->account($payment->value, $currency),
            "refund" => $this->account($refund->value, $currency),
            "charge_sources" => [],
            "payment_methods" => [],
            "periods" => $book["definition"]["periods"] ?? [],
        ];
        if (isset($book['definition']['service_fees'])) {
            $definition['service_fees'] = $book['definition']['service_fees'];
        }
        $mappingDefinitions = [
            "charge_sources" => [$sources, ChargeSource::class],
            "payment_methods" => [$methods, PaymentMethod::class],
        ];
        foreach ($mappingDefinitions as $field => [$rows, $enum]) {
            foreach ($rows as $row) {
                $enum::from($row["key"]);
                if (isset($definition[$field][$row["key"]])) {
                    throw new DomainException(
                        "A mapping key can be selected only once.",
                    );
                }
                $definition[$field][$row["key"]] = $this->account(
                    $row["account_id"],
                    $currency,
                );
            }
            ksort($definition[$field]);
        }
        LedgerSchema::book($definition);
        $this->fresh($actor);
        return $this->issue(
            "mapping",
            (string) LedgerBookId::forCurrency($currency),
            $definition,
            $book["position"] ?? 0,
            $book["position"] ?? 0,
            $reason,
            $actor,
        );
    }

    public function reviewPeriod(
        string $currency,
        string $label,
        string $start,
        string $end,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->actor();
        $book =
        $this->control->book($currency) ??
        throw new DomainException(
            "Publish the student-account mapping first.",
        );
        $definition = [
            "book_id" => $book["id"],
            "currency" => $currency,
            "label" => trim($label),
            "starts_on" => $start,
            "ends_on" => $end,
            "closed" => false,
            "movement_count" => 0,
            "digest" => LedgerSchema::ZERO,
            "ar_net" => "0",
        ];
        LedgerSchema::period($definition);
        foreach ($book["definition"]["periods"] as $period) {
            if ($period["starts_on"] <= $end && $period["ends_on"] >= $start) {
                throw new DomainException("Accounting periods cannot overlap.");
            }
        }
        $this->fresh($actor);
        return $this->issue(
            "open",
            EntityId::generate()->value,
            $definition,
            0,
            $book["position"],
            $this->commands->reason($reason),
            $actor,
        );
    }

    public function reviewClosing(
        EntityId $period,
        bool $reopen,
        ?string $reconciliation,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->actor(true);
        $current = $this->control->period($period->value);
        $definition = $current["definition"];
        if ($definition["closed"] !== $reopen) {
            throw new DomainException(
                "The accounting period is already in that state.",
            );
        }
        if (!$reopen) {
            $scan = $this->open($reconciliation ?? "", "scan", $actor);
            if (
                $scan["id"] !== $period->value ||
                $scan["position"] !== $current["position"] ||
                !$scan["done"] ||
                $scan["digest"] !== $definition["digest"] ||
                $scan["ar_net"] !== $definition["ar_net"] ||
                $scan["after"] !== $definition["movement_count"]
            ) {
                throw new ConcurrentModificationException(
                    "Complete a current reconciliation before closing this period.",
                );
            }
        }
        $book =
        $this->control->book($definition["currency"]) ??
        throw new CorruptHistoryException();
        $definition["closed"] = !$reopen;
        return $this->issue(
            $reopen ? "reopen" : "close",
            $period->value,
            $definition,
            $current["position"],
            $book["position"],
            $this->commands->reason($reason),
            $actor,
        );
    }

    public function reviewServiceFees(string $currency, array $input, string $reason): array
    {
        $actor = $this->actor();
        $this->commands->assertActor($actor, 'sis:fees:configure');
        $book = $this->control->book($currency) ?? throw new DomainException('Publish the currency mapping first.');
        \Domain\Finance\ValueObject\LedgerSchema::keys(
            $input,
            ['cash', 'liability', 'expense', 'application', 'transcript', 'recognition']
        );
        $map = ['recognition' => $input['recognition']];
        foreach (
            ['cash' => 'asset', 'liability' => 'liability', 'expense' => 'expense',
            'application' => 'income', 'transcript' => 'income'] as $key => $kind
        ) {
            $map[$key] = $this->classified($input[$key], $currency, $kind, $book['definition']['receivable']['id']);
        }
        \Domain\ServiceFee\ValueObject\FeeAccounting::mapping($map);
        $definition = $book['definition'];
        $definition['service_fees'] = $map;
        $this->fresh($actor);
        $this->commands->assertActor($actor, 'sis:fees:configure');
        return $this->issue(
            'fee_mapping',
            $book['id'],
            $definition,
            $book['position'],
            $book['position'],
            $this->commands->reason($reason),
            $actor
        );
    }

    private function classified(string $id, string $currency, string $kind, string $receivable): array
    {
        $row = $this->control->records->account($id);
        if ($row['currency'] !== $currency || $row['kind'] !== $kind || $id === $receivable) {
            throw new DomainException('Choose a distinct account with the required classification and currency.');
        }
        return $this->account($id, $currency);
    }

    public function reviewExpense(
        string $currency,
        int $minor,
        EntityId $asset,
        string $reference,
        string $date,
        string $reason,
    ): array {
        $actor = $this->actor();
        $book = $this->control->book($currency) ?? throw new DomainException('Publish the currency mapping first.');
        $map = $book['definition']['service_fees'] ?? throw new DomainException('Configure fee accounting first.');
        if ($minor <= 0) {
            throw new DomainException('Enter the positive processing expense actually charged.');
        }
        $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($on === false || $on->format('Y-m-d') !== $date) {
            throw new DomainException('Choose an explicit accounting date.');
        }
        $definition = ['currency' => $currency, 'minor' => $minor, 'reference' => $reference,
            'posted_on' => $date, 'asset' => $this->classified(
                $asset->value,
                $currency,
                'asset',
                $book['definition']['receivable']['id']
            ), 'expense' => $map['expense']];
        $this->classified($map['expense']['id'], $currency, 'expense', $book['definition']['receivable']['id']);
        $id = new JournalId();
        \Domain\Finance\Journal::post(
            $id,
            $reference,
            new \DateTimeImmutable($date),
            $this->expenseLines($definition),
            $actor,
            $this->control->accounting($currency, $date)
        );
        $this->fresh($actor);
        return $this->issue(
            'expense',
            (string) $id,
            $definition,
            0,
            $book['position'],
            $this->commands->reason($reason),
            $actor
        );
    }

    private function expenseLines(array $d): array
    {
        return [new \Domain\Finance\LedgerLine(
            new EntityId($d['expense']['id']),
            new Money($d['minor'], $d['currency'])
        ),
            new \Domain\Finance\LedgerLine(new EntityId($d['asset']['id']), new Money(-$d['minor'], $d['currency']))];
    }

    private function issue(
        string $action,
        string $id,
        array $definition,
        int $position,
        int $bookPosition,
        #[\SensitiveParameter] string $reason,
        EntityId $actor,
    ): array {
        $claim = [
            "kind" => "review",
            "action" => $action,
            "id" => $id,
            "definition" => $definition,
            "position" => $position,
            "book_position" => $bookPosition,
            "reason" => $reason,
            "actor" => $actor->value,
            "operation" => EntityId::generate()->value,
            "issued" => $this->clock->now()->getTimestamp(),
        ];
        return [
            "action" => $action,
            "definition" => $definition,
            "position" => $position,
            "review_token" => $this->seal($claim),
        ];
    }

    public function confirm(#[\SensitiveParameter] string $token): array
    {
        $actor = $this->actor();
        $claim = $this->open($token, "review", $actor, false);
        if ($claim['action'] === 'fee_mapping') {
            $this->commands->assertActor($actor, 'sis:fees:configure');
        }
        $closing = in_array($claim["action"], ["close", "reopen"], true);
        $this->fresh($actor, $closing);
        return $this->transactions->run(function () use (
            $token,
            $claim,
            $actor,
            $closing,
        ): array {
            $this->control->records->lock();
            $operation = new EntityId($claim["operation"]);
            $saved = $this->evidence->operationEvents($operation);
            if ($claim['action'] === 'expense') {
                $posted = $this->control->postedJournal($claim['id']);
                $saved = $posted === null ? null : [$posted];
            }
            $definition = $claim["definition"];
            if ($saved !== null) {
                $stored = $saved[0];
                $p = $stored->event->payload();
                if ($claim['action'] === 'expense') {
                    $expected = array_map(static fn($line): array => ['account_id' => $line->accountId->value,
                        'minor' => $line->amount->minor], $this->expenseLines($definition));
                    if (
                        !$stored->event instanceof JournalWasPosted || $p['lines'] !== $expected
                        || $p['reference'] !== $definition['reference'] || $p['posted_on'] !== $definition['posted_on']
                        || $p['currency'] !== $definition['currency']
                        || $stored->context->actor?->value !== $actor->value
                        || $stored->context->reason !== $claim['reason']
                        || $stored->context->origin !== 'command'
                        || $stored->context->correlation?->value !== $claim['operation']
                        || (string) $stored->event->aggregateId() !== $claim['id']
                    ) {
                        throw new ConcurrentModificationException('This expense belongs to another instruction.');
                    }
                    $this->fresh($actor);
                    return ['id' => $claim['id'], 'action' => 'expense', 'position' => 1];
                }
                if (
                    $stored->context->actor?->value !== $actor->value ||
                    (string) $stored->event->aggregateId() !== $claim["id"] ||
                    !(
                        $stored->event instanceof LedgerBookWasPublished ||
                        $stored->event instanceof LedgerPeriodWasRecorded
                    ) ||
                    LedgerSchema::hash($p["definition"]) !==
                        LedgerSchema::hash($definition) ||
                    $stored->context->reason !== $claim["reason"]
                ) {
                    throw new ConcurrentModificationException(
                        "This accounting operation belongs to another instruction.",
                    );
                }
                $this->control->validateDecision($stored);
                if ($claim['action'] === 'fee_mapping') {
                    $this->commands->assertActor($actor, 'sis:fees:configure');
                }
                $this->fresh($actor, $closing);
                return [
                    "id" => $claim["id"],
                    "action" => $claim["action"],
                    "position" => $stored->event->playhead(),
                ];
            }
            $this->open($token, "review", $actor);
            $book = $this->control->book($definition["currency"]);
            if (($book["position"] ?? 0) !== $claim["book_position"]) {
                throw new ConcurrentModificationException(
                    "The ledger mapping or period catalog changed. Review again.",
                );
            }
            $context = $this->commands->context(
                $actor,
                $operation,
                $claim["reason"],
            );
            if ($claim['action'] === 'fee_mapping') {
                $this->commands->assertActor($actor, 'sis:fees:configure');
                foreach (
                    ['cash' => 'asset', 'liability' => 'liability', 'expense' => 'expense',
                    'application' => 'income', 'transcript' => 'income'] as $key => $kind
                ) {
                    $expected = $definition['service_fees'][$key];
                    if (
                        LedgerSchema::hash($this->classified(
                            $expected['id'],
                            $definition['currency'],
                            $kind,
                            $book['definition']['receivable']['id']
                        )) !== LedgerSchema::hash($expected)
                    ) {
                        throw new ConcurrentModificationException('A selected account changed. Review again.');
                    }
                }
                $this->control->saveBook($claim['id'], $definition, $claim['position'], $context, $operation);
            } elseif ($claim['action'] === 'expense') {
                foreach (['asset' => 'asset', 'expense' => 'expense'] as $key => $kind) {
                    if (
                        LedgerSchema::hash($this->classified(
                            $definition[$key]['id'],
                            $definition['currency'],
                            $kind,
                            $book['definition']['receivable']['id']
                        )) !== LedgerSchema::hash($definition[$key])
                    ) {
                        throw new ConcurrentModificationException('A selected expense account changed. Review again.');
                    }
                }
                $journal = \Domain\Finance\Journal::post(
                    new JournalId($claim['id']),
                    $definition['reference'],
                    new \DateTimeImmutable($definition['posted_on']),
                    $this->expenseLines($definition),
                    $actor,
                    $this->control->accounting($definition['currency'], $definition['posted_on'])
                );
                $this->journals->save($journal, $context);
            } elseif ($claim["action"] === "mapping") {
                if ($book === null) {
                    if (
                        $this->control->records->hasEarlierPostings(
                            $definition["currency"],
                        )
                    ) {
                        throw new ConcurrentModificationException(
                            "Earlier postings prevent activation without an opening-balance conversion.",
                        );
                    }
                    $ar = $definition["receivable"];
                    $this->journals->addLedgerAccount(
                        new EntityId($ar["id"]),
                        $ar["code"],
                        $ar["title"],
                        $definition["currency"],
                        'asset',
                    );
                } elseif (
                    $definition["receivable"]["id"] !==
                    $book["definition"]["receivable"]["id"]
                ) {
                    throw new DomainException(
                        "The accounts-receivable control account cannot be disconnected.",
                    );
                }
                foreach (["charge", "payment", "refund"] as $field) {
                    $this->checkAccount(
                        $definition[$field],
                        $definition["currency"],
                    );
                }
                $mappedOverrides = [
                    ...array_values($definition["charge_sources"]),
                    ...array_values($definition["payment_methods"]),
                ];
                foreach ($mappedOverrides as $account) {
                    $this->checkAccount($account, $definition["currency"]);
                }
                $receivable = $this->control->records->account($definition['receivable']['id']);
                if ($receivable['currency'] !== $definition['currency']) {
                    throw new CorruptHistoryException();
                }
                $this->control->records->renameReceivable(
                    $definition["receivable"],
                );
                $this->control->saveBook(
                    $claim["id"],
                    $definition,
                    $claim["position"],
                    $context,
                    $operation,
                );
            } elseif ($claim["action"] === "open") {
                $this->control->savePeriod(
                    $claim["id"],
                    $definition,
                    0,
                    "open",
                    null,
                    $context,
                    $operation,
                );
                $bookDefinition = $book["definition"];
                $bookDefinition["periods"][] = [
                    "id" => $claim["id"],
                    "starts_on" => $definition["starts_on"],
                    "ends_on" => $definition["ends_on"],
                ];
                usort(
                    $bookDefinition["periods"],
                    static fn(array $a, array $b): int => strcmp(
                        $a["starts_on"],
                        $b["starts_on"],
                    ),
                );
                $this->control->saveBook(
                    $book["id"],
                    $bookDefinition,
                    $book["position"],
                    $context,
                    EntityId::generate(),
                );
            } elseif ($closing) {
                $current = $this->control->period($claim["id"]);
                $prior = $definition;
                $prior["closed"] = !$definition["closed"];
                if (
                    $current["position"] !== $claim["position"] ||
                    LedgerSchema::hash($current["definition"]) !==
                        LedgerSchema::hash($prior)
                ) {
                    throw new ConcurrentModificationException(
                        "The period changed. Reconcile and review it again.",
                    );
                }
                $this->control->savePeriod(
                    $claim["id"],
                    $definition,
                    $claim["position"],
                    $claim["action"],
                    null,
                    $context,
                    $operation,
                );
            } else {
                throw new DomainException(
                    "Choose a supported accounting action.",
                );
            }
            $this->audit->record(
                $actor,
                "ledger." . $claim["action"],
                new EntityId($claim["id"]),
            );
            $this->open($token, "review", $actor);
            $this->fresh($actor, $closing);
            if ($claim['action'] === 'fee_mapping') {
                $this->commands->assertActor($actor, 'sis:fees:configure');
            }
            return [
                "id" => $claim["id"],
                "action" => $claim["action"],
                "position" => $claim["position"] + 1,
            ];
        });
    }

    private function checkAccount(array $expected, string $currency): void
    {
        if (
            LedgerSchema::hash($expected) !==
            LedgerSchema::hash($this->account($expected["id"], $currency))
        ) {
            throw new ConcurrentModificationException(
                "A mapped account changed. Review again.",
            );
        }
    }

    public function reconcile(EntityId $periodId, #[\SensitiveParameter] ?string $token = null): array
    {
        $actor = $this->actor();
        $this->commands->assertActor($actor, "sis:finance:read");
        $period = $this->control->period($periodId->value);
        $d = $period["definition"];
        $scan =
        $token === null
        ? [
                    "kind" => "scan",
                    "id" => $periodId->value,
                    "actor" => $actor->value,
                    "issued" => $this->clock->now()->getTimestamp(),
                    "position" => $period["position"],
                    "after" => 0,
                    "digest" => LedgerSchema::ZERO,
                    "ar_net" => "0",
                    "done" => false,
                ]
        : $this->open($token, "scan", $actor);
        if (
            $scan["id"] !== $periodId->value ||
            $scan["position"] !== $period["position"]
        ) {
            throw new ConcurrentModificationException(
                "This accounting period changed. Start reconciliation again.",
            );
        }
        $book =
        $this->control->book($d["currency"]) ??
        throw new CorruptHistoryException();
        $rows = $this->control->records->movements(
            $periodId->value,
            $scan["after"],
            $d["movement_count"],
        );
        $items = [];
        $started = hrtime(true);
        foreach ($rows as $row) {
            if ($items !== [] && hrtime(true) - $started > 2_000_000_000) {
                break;
            }
            if ((int) $row["ordinal"] !== $scan["after"] + 1) {
                throw new CorruptHistoryException();
            }
            $journal = $this->journals->get(new JournalId($row["journal_id"]));
            $stored = $this->evidence->recordedEvent(
                new EntityId($row["event_id"]),
            );
            if (
                ($journal->accounting()["period_id"] ?? null) !==
                $periodId->value
            ) {
                throw new CorruptHistoryException();
            }
            try {
                $net = $this->control->verifyMovement(
                    $journal,
                    $stored,
                    $book["definition"]["receivable"]["id"],
                    $started,
                );
            } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException $error) {
                if ($items === []) {
                    throw $error;
                }
                break;
            }
            $source =
            $stored->event instanceof JournalWasPosted
            ? $journal->accounting()["source"]
            : null;
            if (
                $row["account_id"] !== ($source["account_id"] ?? null) ||
                $row["entry_operation"] !== ($source["entry_operation"] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $scan["after"]++;
            $scan["digest"] = LedgerSchema::movement(
                $scan["digest"],
                $scan["after"],
                $row["event_id"],
            );
            $scan["ar_net"] = (string) BigInteger::of($scan["ar_net"])->plus(
                $net,
            );
            $items[] = [
                "journal_id" => $row["journal_id"],
                "reference" => $journal->reference(),
                "posted_on" => $journal->postedOn(),
                "student_account_id" => $source["account_id"] ?? null,
                "ar_effect_minor" => $net,
            ];
        }
        $scan["done"] = $scan["after"] === $d["movement_count"];
        if (
            ($rows === [] && !$scan["done"]) ||
            ($scan["done"] &&
                ($scan["digest"] !== $d["digest"] ||
                    $scan["ar_net"] !== $d["ar_net"]))
        ) {
            throw new CorruptHistoryException();
        }
        $current = $this->control->period($periodId->value);
        if ($current["position"] !== $period["position"]) {
            throw new ConcurrentModificationException(
                "This accounting period changed. Start reconciliation again.",
            );
        }
        $this->audit->record($actor, "ledger.reconciliation_read", $periodId);
        $this->fresh($actor);
        $this->commands->assertActor($actor, "sis:finance:read");
        return [
            "items" => $items,
            "checked" => $scan["after"],
            "total" => $d["movement_count"],
            "ar_net_minor" => $scan["ar_net"],
            "currency" => $d["currency"],
            "done" => $scan["done"],
            "continuation" => $this->seal($scan),
        ];
    }

    private function seal(#[\SensitiveParameter] array $claim): string
    {
        return $this->cipher->seal(json_encode($claim, JSON_THROW_ON_ERROR));
    }

    private function open(
        #[\SensitiveParameter] string $token,
        string $kind,
        EntityId $actor,
        bool $fresh = true,
    ): array {
        try {
            if (strlen($token) > 262144) {
                throw new DomainException();
            }
            $claim = json_decode(
                $this->cipher->open($token),
                true,
                20,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($claim) ||
                ($claim["kind"] ?? null) !== $kind ||
                !is_int($claim["issued"] ?? null) ||
                !is_string($claim["actor"] ?? null)
            ) {
                throw new DomainException();
            }
        } catch (\Throwable) {
            throw new DomainException(
                "Review this accounting action again before continuing.",
            );
        }
        if ($claim["actor"] !== $actor->value) {
            throw new AccessDeniedException(
                "This accounting review belongs to another staff member.",
            );
        }
        $age = $this->clock->now()->getTimestamp() - $claim["issued"];
        if ($fresh && ($age < 0 || $age >= 1200)) {
            throw new ConcurrentModificationException(
                "This accounting review expired. Start again.",
            );
        }
        return $claim;
    }
}
