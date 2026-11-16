<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\EventSourcing\EventContext;
use Infrastructure\EventSourcing\FinancialEventWriter;
use Qubus\Expressive\Database;

final readonly class EventSourcedAccountRepository implements AccountRepository
{
    public function __construct(
        private TransactionalEventStore $eventStore,
        private Database $db,
        private FinancialEventWriter $writer,
    ) {
    }

    public function get(AccountId $id): StudentAccount
    {
        $history = $this->eventStore->getAggregateHistoryFor($id);
        if (count($history) === 0) {
            throw new RecordNotFoundException('Account not found.');
        }
        $account = StudentAccount::reconstituteFromEventStream($history);
        if (!$account instanceof StudentAccount) {
            throw new \LogicException('The account event stream could not be restored.');
        }

        return $account;
    }

    public function save(StudentAccount $account, #[\SensitiveParameter] EventContext $context): void
    {
        if (!$account->hasRecordedEvents()) {
            return;
        }
        // The event-store unique stream/playhead constraint rejects concurrent writes.
        $events = [];
        foreach ($account->getRecordedEvents() as $event) {
            if (!$event instanceof \Codefy\Domain\EventSourcing\DomainEvent) {
                throw new \LogicException('Invalid financial event.');
            }
            $events[] = $event;
        }
        if ($events === []) {
            return;
        }
        \Infrastructure\Persistence\PortableTransactions::run($this->db, function () use (
            $account,
            $events,
            $context,
        ): void {
            if ($events[0] instanceof StudentAccountWasOpened) {
                $this->db->table('sis_student_accounts')->insert([
                    'id' => (string) $account->aggregateId(),
                    'student_id' => $account->studentId()->value,
                    'term_id' => $account->termId()->value,
                    'currency' => $account->balance()->currency,
                ]);
            }
            $this->writer->append($account, $context);
            new \Infrastructure\Projection\SqlAccountBalanceProjection(
                new \Infrastructure\Persistence\SqlRecords($this->db)
            )->advance($account, $events);
        });
        $account->clearRecordedEvents();
    }
}
