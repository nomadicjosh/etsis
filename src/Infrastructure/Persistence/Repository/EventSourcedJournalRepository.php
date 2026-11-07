<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\Finance\Event\JournalWasPosted;
use Domain\Finance\Journal;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\ValueObject\JournalId;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class EventSourcedJournalRepository implements JournalRepository
{
    public function __construct(
        private SqlRecords $sql,
        private TransactionalEventStore $events,
        private UnitOfWork $transactions
    ) {
    }

    public function addLedgerAccount(EntityId $id, string $code, string $title, string $currency): void
    {
        $this->sql->insert('sis_ledger_accounts', ['id' => $id->value, 'code' => $code,
            'title' => $title, 'currency' => $currency]);
    }

    public function ledgerCurrency(EntityId $id): string
    {
        return $this->sql->one('sis_ledger_accounts', ['id' => $id->value])['currency'];
    }

    public function get(JournalId $id): Journal
    {
        $history = $this->events->getAggregateHistoryFor($id);
        if (count($history) === 0) {
            throw new RecordNotFoundException('Journal not found.');
        }
        $journal = Journal::reconstituteFromEventStream($history);
        if (!$journal instanceof Journal) {
            throw new \LogicException('Invalid journal aggregate.');
        }

        return $journal;
    }

    public function save(Journal $journal): void
    {
        $events = iterator_to_array($journal->getRecordedEvents());
        if ($events === []) {
            return;
        }
        $this->transactions->run(function () use ($journal, $events): void {
            if ($events[0] instanceof JournalWasPosted) {
                $this->sql->insert('sis_journals', ['id' => (string) $journal->aggregateId(),
                    'reference' => $journal->reference(), 'currency' => $journal->currency(),
                        'posted_on' => $journal->postedOn()]);
            }
            $this->events->commit(...$events);
        });
        $journal->clearRecordedEvents();
    }
}
