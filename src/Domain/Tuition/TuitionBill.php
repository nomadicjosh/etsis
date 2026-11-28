<?php

declare(strict_types=1);

namespace Domain\Tuition;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\RegistrationPrice;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\ValueObject\TuitionSchema;

final class TuitionBill extends EventSourcedAggregate
{
    public const array FIELDS = ["operation_id", "action", "details", "intent"];
    private array $data = [
        "student_id" => null,
        "term_id" => null,
        "snapshot" => null,
        "courses" => [],
        "covered" => [],
        "invoices" => [],
    ];
    private array $receipts = [];

    public static function validate(array $p): void
    {
        LedgerSchema::keys($p, self::FIELDS);
        new EntityId($p["operation_id"]);
        if (
            !is_string($p["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["intent"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        $d = $p["details"];
        switch ($p["action"]) {
            case "start":
                LedgerSchema::keys($d, ["student_id", "term_id", "snapshot"]);
                new EntityId($d["student_id"]);
                new EntityId($d["term_id"]);
                self::snapshot($d["snapshot"]);
                break;
            case "course":
                TuitionSchema::course($d);
                break;
            case "remove":
                LedgerSchema::keys($d, ["enrollment_id"]);
                new EntityId($d["enrollment_id"]);
                break;
            case "reprice":
                LedgerSchema::keys($d, ["snapshot"]);
                self::snapshot($d["snapshot"]);
                break;
            case "invoice":
                LedgerSchema::keys($d, [
                    "invoice_id",
                    "reference",
                    "issued_at",
                    "due_on",
                    "account_id",
                    "lines",
                    "recipient",
                    "currency",
                ]);
                new Money(0, $d["currency"]);
                LedgerSchema::keys($d["recipient"], [
                    "person_id",
                    "name",
                    "institutional_id",
                    "core_reference",
                ]);
                new EntityId($d["recipient"]["person_id"]);
                $core = $d["recipient"]["core_reference"];
                LedgerSchema::keys($core, [
                    "person_id",
                    "core_version",
                    "history_recorded",
                    "earlier_history_unknown",
                ]);
                if (
                    $core["person_id"] !== $d["recipient"]["person_id"] ||
                    !is_int($core["core_version"]) ||
                    $core["core_version"] < 0 ||
                    !is_bool($core["history_recorded"]) ||
                    $core["history_recorded"] !== $core["core_version"] > 0 ||
                    !is_bool($core["earlier_history_unknown"])
                ) {
                    throw new CorruptHistoryException();
                }
                TuitionSchema::text($d["recipient"]["name"], 400);
                TuitionSchema::text($d["recipient"]["institutional_id"], 80);
                new EntityId($d["invoice_id"]);
                if ($d["account_id"] !== null) {
                    new EntityId($d["account_id"]);
                }
                TuitionSchema::text($d["reference"], 80);
                if (
                    !preg_match(
                        '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',
                        $d["issued_at"],
                    ) ||
                    !preg_match('/^\d{4}-\d\d-\d\d$/D', $d["due_on"]) ||
                    !array_is_list($d["lines"]) ||
                    count($d["lines"]) < 1 ||
                    count($d["lines"]) > 100
                ) {
                    throw new CorruptHistoryException();
                }
                $issued = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $d['issued_at']);
                $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['due_on']);
                if (
                    $issued === false || $issued->format('Y-m-d\\TH:i:s\\Z') !== $d['issued_at']
                    || $due === false || $due->format('Y-m-d') !== $d['due_on']
                    || $d['reference'] !== 'INV-' . $d['invoice_id']
                ) {
                    throw new CorruptHistoryException();
                }
                foreach ($d["lines"] as $line) {
                    TuitionSchema::line($line);
                }
                break;
            default:
                throw new CorruptHistoryException();
        }
    }

    public static function snapshot(array $s): void
    {
        LedgerSchema::keys($s, [
            "schedule_id",
            "schedule_event_id",
            "assignment_event_id",
            "definition",
            "book_event_id",
            "fee_accounts",
            "rules",
        ]);
        foreach (
            [
                "schedule_id",
                "schedule_event_id",
                "assignment_event_id",
                "book_event_id",
            ] as $key
        ) {
            new EntityId($s[$key]);
        }
        TuitionSchema::definition($s["definition"]);
        LedgerSchema::keys($s["fee_accounts"], ["section", "term", "credit"]);
        foreach ($s["fee_accounts"] as $account) {
            LedgerSchema::account($account);
        }
        if (!array_is_list($s["rules"]) || count($s["rules"]) > 50) {
            throw new CorruptHistoryException();
        }
        foreach ($s["rules"] as $r) {
            LedgerSchema::keys($r, ["id", "name", "minor", "basis"]);
            new EntityId($r["id"]);
            TuitionSchema::text($r["name"], 191);
            \Domain\Finance\Enum\BillingBasis::from($r["basis"]);
            if (
                !is_int($r["minor"]) ||
                $r["minor"] < 1 ||
                $r["minor"] > Money::MAX_MINOR
            ) {
                throw new CorruptHistoryException();
            }
        }
    }

    public function accept(StoredEvent $stored): void
    {
        $p = $stored->event->payload();
        self::validate($p);
        if (
            !(
                $stored->event instanceof
                \Domain\Tuition\Event\TuitionBillActionWasRecorded
            ) ||
            !$stored->event->aggregateId()->equals($this->aggregateId()) ||
            $stored->event->playhead() !== $this->playhead() + 1 ||
            $this->playhead() >= 500 ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->origin !== "command" ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            isset($this->receipts[$p["operation_id"]]) ||
            $p["intent"] !==
                LedgerSchema::hash([
                    $p["action"],
                    $p["details"],
                    $stored->context->reason,
                ])
        ) {
            throw new CorruptHistoryException();
        }
        $d = $p["details"];
        if ($p["action"] === "start") {
            if ($this->playhead() !== 0) {
                throw new CorruptHistoryException();
            }
            $this->data = $d + [
                "courses" => [],
                "covered" => [],
                "invoices" => [],
            ];
        } else {
            if ($this->data["student_id"] === null) {
                throw new CorruptHistoryException();
            }
            if ($p["action"] === "course") {
                if (
                    isset($this->data["courses"][$d["enrollment_id"]]) ||
                    count($this->data["courses"]) >= 50
                ) {
                    throw new CorruptHistoryException();
                }
                $this->data["courses"][$d["enrollment_id"]] = $d;
            } elseif ($p["action"] === "remove") {
                if (!isset($this->data["courses"][$d["enrollment_id"]])) {
                    throw new CorruptHistoryException();
                }
                unset($this->data["courses"][$d["enrollment_id"]]);
            } elseif ($p["action"] === "reprice") {
                if (
                    $this->data["invoices"] !== [] ||
                    $d["snapshot"]["definition"]["currency"] !==
                        $this->data["snapshot"]["definition"]["currency"]
                ) {
                    throw new \DomainException(
                        "Issued terms retain their original tuition schedule.",
                    );
                }
                $this->data["snapshot"] = $d["snapshot"];
            } else {
                if (
                    $d["currency"] !==
                    $this->data["snapshot"]["definition"]["currency"]
                ) {
                    throw new CorruptHistoryException();
                }
                $pending = array_column($this->pending()["lines"], null, "key");
                if (
                    count($pending) !== count($d["lines"]) ||
                    isset($this->data["invoices"][$d["invoice_id"]])
                ) {
                    throw new CorruptHistoryException();
                }
                $seen = [];
                $total = 0;
                foreach ($d["lines"] as $line) {
                    $expected =
                    $pending[$line["key"]] ??
                    throw new CorruptHistoryException();
                    if (
                        isset($seen[$line["key"]]) ||
                        LedgerSchema::hash(
                            array_replace($line, [
                                "minor" => $expected["minor"],
                            ]),
                        ) !== LedgerSchema::hash($expected)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $seen[$line["key"]] = true;
                    $total += $line["minor"];
                    $this->data["covered"][$line["key"]] =
                    ($this->data["covered"][$line["key"]] ?? 0) +
                    $line["quoted_minor"];
                }
                if (
                    $total > Money::MAX_MINOR ||
                    ($total > 0 && $d["account_id"] === null)
                ) {
                    throw new CorruptHistoryException();
                }
                $this->data["invoices"][$d["invoice_id"]] = $d;
            }
        }
        if (strlen(json_encode($this->data, JSON_THROW_ON_ERROR)) > 2097152) {
            throw new \DomainException(
                "The term billing case exceeds its retained data limit.",
            );
        }
        $this->playhead = $stored->event->playhead();
        $this->receipts[$p["operation_id"]] = [
            "intent" => $p["intent"],
            "actor" => $stored->context->actor->value,
        ];
    }

    public function receipt(string $id): ?array
    {
        return $this->receipts[$id] ?? null;
    }

    public function state(): array
    {
        return $this->data;
    }

    public function pending(): array
    {
        $s = $this->data["snapshot"];
        if ($s === null) {
            return [
                "lines" => [],
                "credits_milli" => 0,
                "adjustment_minor" => 0,
                "total_minor" => 0,
            ];
        }
        $credits = array_sum(
            array_column($this->data["courses"], "credits_milli"),
        );
        $currency = $s["definition"]["currency"];
        $targets = [];
        $add = static function (
            $key,
            $name,
            $minor,
            $account,
            $source,
            $enrollment = null,
            $price = null,
        ) use (&$targets): void {
            if ($minor > 0) {
                $targets[] = [
                    "key" => $key,
                    "name" => $name,
                    "quoted_minor" => $minor,
                    "minor" => $minor,
                    "account" => $account,
                    "enrollment_id" => $enrollment,
                    "price" => $price,
                    "source" => $source,
                ];
            }
        };
        $tuition = TuitionSchema::amount($s["definition"], $credits);
        $adjustment = max(
            0,
            ($this->data["covered"]["tuition"] ?? 0) - $tuition,
        );
        $add(
            "tuition",
            $s["definition"]["name"],
            $tuition,
            $s["definition"]["income"],
            "term_tuition",
        );
        foreach ($this->data["courses"] as $course) {
            $add(
                "section:" . $course["enrollment_id"],
                $course["section_label"],
                $course["minor"],
                $s["fee_accounts"]["section"],
                "section_fee",
                $course["enrollment_id"],
            );
        }
        foreach ($s["rules"] as $rule) {
            if ($rule["basis"] === "term" && $this->data["courses"] !== []) {
                $add(
                    "rule:" . $rule["id"],
                    $rule["name"],
                    $rule["minor"],
                    $s["fee_accounts"]["term"],
                    "term_fee",
                );
            } elseif ($rule["basis"] === "registered_credit") {
                foreach ($this->data["courses"] as $course) {
                    $price = new RegistrationPrice(
                        new EntityId($course["enrollment_id"]),
                        new EntityId($rule["id"]),
                        $rule["name"],
                        $course["credits_milli"],
                        new Money($rule["minor"], $currency),
                    );
                    $add(
                        "credit:" .
                            $rule["id"] .
                            ":" .
                            $course["enrollment_id"],
                        $rule["name"],
                        $price->amount->minor,
                        $s["fee_accounts"]["credit"],
                        "credit_fee",
                        $course["enrollment_id"],
                        $price->values(),
                    );
                }
            }
        }
        $lines = [];
        foreach ($targets as $line) {
            $minor =
            $line["minor"] - ($this->data["covered"][$line["key"]] ?? 0);
            if ($minor > 0) {
                $lines[] = array_replace($line, [
                    "minor" => $minor,
                    "quoted_minor" => $minor,
                ]);
            }
        }
        if (
            count($lines) > 100 ||
            array_sum(array_column($lines, "minor")) > Money::MAX_MINOR
        ) {
            throw new \DomainException(
                "The pending invoice exceeds its supported size or amount.",
            );
        }
        return [
            "lines" => $lines,
            "credits_milli" => $credits,
            "adjustment_minor" => $adjustment,
            "total_minor" => array_sum(array_column($lines, "minor")),
        ];
    }

    public static function estimate(
        array $snapshot,
        array $state,
        array $course,
        ?string $remove = null,
    ): array {
        $root = self::root(new \Domain\Tuition\ValueObject\TuitionBillId());
        $root->data = $state;
        $root->data["snapshot"] = $snapshot;
        if ($remove !== null) {
            unset($root->data["courses"][$remove]);
        }
        $root->data["courses"][$course["enrollment_id"]] = $course + [
            "section_label" => "Section fee",
        ];
        return ["state" => $root->data, "pending" => $root->pending()];
    }
}
