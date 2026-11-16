<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\StudentAccount;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

/** Reconstructible current balance, never a second financial ledger. Caller owns the transaction. */
final readonly class SqlAccountBalanceProjection
{
    public function __construct(private SqlRecords $sql)
    {
    }

    /** @param non-empty-list<DomainEvent> $events */
    public function advance(StudentAccount $account, array $events): void
    {
        $id = (string) $account->aggregateId();
        $expected = $events[0]->playhead() - 1;
        $previous = $this->sql->current('sis_account_balances', $id);
        $delta = 0;
        foreach ($events as $event) {
            if ($event instanceof StudentAccountWasOpened) {
                continue;
            }
            if (!$event instanceof AccountEntryWasPosted && !$event instanceof AccountEntryWasReversed) {
                throw new CorruptHistoryException();
            }
            $entry = $account->entry(new \Domain\Shared\ValueObject\EntityId($event->payload()['operation_id']));
            if ($entry === null) {
                throw new CorruptHistoryException();
            }
            $change = $entry['type'] === EntryType::Payment->value ? -$entry['minor'] : $entry['minor'];
            $delta += $event instanceof AccountEntryWasReversed ? -$change : $change;
        }
        if ($expected === 0) {
            if ($previous !== null || !$events[0] instanceof StudentAccountWasOpened) {
                throw new CorruptHistoryException();
            }
            $this->sql->insert('sis_account_balances', ['id' => $id] + $this->values($account));
            return;
        }
        if (
            $previous === null || (int) $previous['position'] !== $expected
            || (int) $previous['balance_minor'] !== $account->balance()->minor - $delta
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->sql->update(
                'sis_account_balances',
                $this->values($account),
                ['id' => $id, 'position' => $expected]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The account balance changed. Review the account again.');
        }
    }

    /** Explicit migration/rebuild only. A different retained projection must be investigated. */
    public function initialize(StudentAccount $account): void
    {
        $id = (string) $account->aggregateId();
        $values = $this->values($account);
        $old = $this->sql->current('sis_account_balances', $id);
        if ($old === null) {
            $this->sql->insert('sis_account_balances', ['id' => $id] + $values);
        } elseif (
            (int) $old['balance_minor'] !== $values['balance_minor']
            || (int) $old['position'] !== $values['position']
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array{balance_minor:int,position:int} */
    private function values(StudentAccount $account): array
    {
        return ['balance_minor' => $account->balance()->minor, 'position' => $account->playhead()];
    }
}
