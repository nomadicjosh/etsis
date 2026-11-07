<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\Exception\RecordNotFoundException;
use Qubus\Expressive\Database;

final readonly class EventSourcedAccountRepository implements AccountRepository
{
    public function __construct(private TransactionalEventStore $eventStore, private Database $db)
    {
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

    public function save(StudentAccount $account): void
    {
        if (!$account->hasRecordedEvents()) {
            return;
        }
        // The event-store unique stream/playhead constraint rejects concurrent writes.
        $events = iterator_to_array($account->getRecordedEvents());
        \Infrastructure\Persistence\PortableTransactions::run($this->db, function () use ($account, $events): void {
            if ($events[0] instanceof StudentAccountWasOpened) {
                $this->db->table('sis_student_accounts')->insert([
                    'id' => (string) $account->aggregateId(),
                    'student_id' => $account->studentId()->value,
                    'term_id' => $account->termId()->value,
                    'currency' => $account->balance()->currency,
                ]);
            }
            $this->eventStore->commit(...$events);
        });
        $account->clearRecordedEvents();
    }
}
