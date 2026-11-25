<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountChargeWasCredited;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\StudentAccount;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

/** Reconstructible current balance, never a second financial ledger. Caller owns the transaction. */
final readonly class SqlAccountBalanceProjection
{
    public const array ENTRY_TOTALS = [
        "charge" => "charges_minor",
        "payment" => "payments_minor",
        "refund" => "refunds_minor",
    ];

    public function __construct(private SqlRecords $sql)
    {
    }

    /** @param non-empty-list<DomainEvent> $events */
    public function advance(StudentAccount $account, array $events): void
    {
        $id = (string) $account->aggregateId();
        $expected = $events[0]->playhead() - 1;
        $previous = $this->sql->current("sis_account_balances", $id);
        $values = $this->values($account);
        $delta = 0;
        $changes = array_fill_keys(array_values(self::ENTRY_TOTALS), 0);
        foreach ($events as $event) {
            if (
                $event instanceof StudentAccountWasOpened ||
                $event instanceof \Domain\Finance\Event\AccountPaymentDetailsWereChanged
            ) {
                continue;
            }
            if ($event instanceof AccountEntryWasCorrected) {
                $p = $event->payload();
                $source = $account->entry(new \Domain\Shared\ValueObject\EntityId($p["source_operation_id"]));
                $replacement = $account->entry(new \Domain\Shared\ValueObject\EntityId($p["operation_id"]));
                if ($source === null || $replacement === null || $source["type"] !== $replacement["type"]) {
                    throw new CorruptHistoryException();
                }
                $difference = $replacement["minor"] - $source["minor"];
                $delta += $source["type"] === EntryType::Payment->value ? -$difference : $difference;
                $column = self::ENTRY_TOTALS[$source["type"]] ?? throw new CorruptHistoryException();
                $changes[$column] += $difference;
                continue;
            }
            if ($event instanceof AccountChargeWasCredited) {
                $entry = $account->entry(
                    new \Domain\Shared\ValueObject\EntityId($event->payload()["operation_id"]),
                );
                if (
                    $entry === null ||
                    $entry["type"] !== EntryType::ChargeCredit->value ||
                    ($entry["source_operation_id"] ?? null) !== $event->payload()["source_operation_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $delta -= $entry["minor"];
                $changes["charges_minor"] -= $entry["minor"];
                continue;
            }
            if (!($event instanceof AccountEntryWasPosted) && !($event instanceof AccountEntryWasReversed)) {
                throw new CorruptHistoryException();
            }
            $entry = $account->entry(
                new \Domain\Shared\ValueObject\EntityId($event->payload()["operation_id"]),
            );
            if ($entry === null) {
                throw new CorruptHistoryException();
            }
            $change = EntryType::from($entry["type"])->balanceSign() * $entry["minor"];
            $delta += $event instanceof AccountEntryWasReversed ? -$change : $change;
            $column =
            $entry["type"] === EntryType::ChargeCredit->value
            ? "charges_minor"
            : self::ENTRY_TOTALS[$entry["type"]] ?? throw new CorruptHistoryException();
            $category =
            $entry["type"] === EntryType::ChargeCredit->value ? -$entry["minor"] : $entry["minor"];
            $changes[$column] += $event instanceof AccountEntryWasReversed ? -$category : $category;
            foreach ($event->metadata()["reversed_credit_ids"] ?? [] as $creditId) {
                $credit = $account->entry(new \Domain\Shared\ValueObject\EntityId($creditId));
                if (
                    $credit === null ||
                    $credit["type"] !== EntryType::ChargeCredit->value ||
                    !$credit["reversed"] ||
                    ($credit["source_operation_id"] ?? null) !== $entry["operation_id"] ||
                    ($credit["reversed_with_operation_id"] ?? null) !== $entry["operation_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $delta += $credit["minor"];
                $changes["charges_minor"] += $credit["minor"];
            }
        }
        if ($expected === 0) {
            if ($previous !== null || !($events[0] instanceof StudentAccountWasOpened)) {
                throw new CorruptHistoryException();
            }
            $this->sql->insert("sis_account_balances", ["id" => $id] + $values);
            return;
        }
        if (
            $previous === null ||
            (int) $previous["position"] !== $expected ||
            (int) $previous["balance_minor"] !== $account->balance()->minor - $delta
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($changes as $column => $change) {
            if (!isset($previous[$column]) || (int) $previous[$column] !== $values[$column] - $change) {
                throw new CorruptHistoryException();
            }
        }
        if (
            $this->sql->update("sis_account_balances", $values, ["id" => $id, "position" => $expected]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The account balance changed. Review the account again.",
            );
        }
    }

    /** Read-only comparison against the reconstructed account; never initializes or repairs data. */
    public function snapshot(#[\SensitiveParameter] StudentAccount $account): array
    {
        return ['id' => (string) $account->aggregateId()] + $this->values($account);
    }

    /** Read-only comparison against the reconstructed account; never initializes or repairs data. */
    public function verify(#[\SensitiveParameter] StudentAccount $account): void
    {
        $row = $this->sql->current('sis_account_balances', (string) $account->aggregateId());
        foreach ($this->values($account) as $field => $value) {
            if ($row === null || !isset($row[$field]) || (string) $row[$field] !== (string) $value) {
                throw new CorruptHistoryException();
            }
        }
    }

    /** Explicit migration/rebuild only. A different retained projection must be investigated. */
    public function initialize(StudentAccount $account): void
    {
        $id = (string) $account->aggregateId();
        $values = $this->values($account);
        $old = $this->sql->current("sis_account_balances", $id);
        if ($old === null) {
            $this->sql->insert("sis_account_balances", ["id" => $id] + $values);
        } elseif (
            (int) $old["balance_minor"] !== $values["balance_minor"] ||
            (int) $old["position"] !== $values["position"]
        ) {
            throw new CorruptHistoryException();
        }
        if ($old !== null) {
            $missing = false;
            foreach (self::ENTRY_TOTALS as $column) {
                if (!array_key_exists($column, $old)) {
                    throw new CorruptHistoryException();
                }
                if ($old[$column] === null) {
                    $missing = true;
                } elseif ((int) $old[$column] !== $values[$column]) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                $missing &&
                $this->sql->update("sis_account_balances", $values, [
                    "id" => $id,
                    "position" => $account->playhead(),
                ]) !== 1
            ) {
                throw new ConcurrentModificationException(
                    "The account changed during projection conversion.",
                );
            }
        }
    }

    /** @return array{balance_minor:int,position:int,charges_minor:int,payments_minor:int,refunds_minor:int} */
    private function values(StudentAccount $account): array
    {
        $charges = 0;
        $payments = 0;
        $refunds = 0;
        $credits = 0;
        foreach ($account->entries() as $entry) {
            if (
                $entry["minor"] < 1 ||
                $entry["minor"] > \Domain\Finance\ValueObject\Money::MAX_MINOR ||
                $entry["currency"] !== $account->balance()->currency
            ) {
                throw new CorruptHistoryException();
            }
            if ($entry["reversed"]) {
                continue;
            }
            $amount = $entry["minor"];
            switch ($entry["type"]) {
                case EntryType::Charge->value:
                    $charges = $this->add($charges, $amount);
                    break;
                case EntryType::ChargeCredit->value:
                    $credits = $this->add($credits, $amount);
                    break;
                case EntryType::Payment->value:
                    $payments = $this->add($payments, $amount);
                    break;
                case EntryType::Refund->value:
                    $refunds = $this->add($refunds, $amount);
                    break;
                default:
                    throw new CorruptHistoryException();
            }
        }
        $charges -= $credits;
        if ($charges < 0 || $charges - $payments + $refunds !== $account->balance()->minor) {
            throw new CorruptHistoryException();
        }
        return [
            "balance_minor" => $account->balance()->minor,
            "position" => $account->playhead(),
            "charges_minor" => $charges,
            "payments_minor" => $payments,
            "refunds_minor" => $refunds,
        ];
    }

    private function add(int $total, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $total) {
            throw new CorruptHistoryException();
        }
        return $total + $amount;
    }
}
