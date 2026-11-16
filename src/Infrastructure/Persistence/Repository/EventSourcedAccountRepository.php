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
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Expressive\Database;

final readonly class EventSourcedAccountRepository implements AccountRepository
{
    public function __construct(
        private TransactionalEventStore $eventStore,
        private Database $db,
        private HistoricalEventStore $history,
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
        if ($context->origin !== 'command' || $context->actor === null || $context->reason === null) {
            throw new \InvalidArgumentException('An attributed financial command and protected reason are required.');
        }
        if (!$account->hasRecordedEvents()) {
            return;
        }
        // The event-store unique stream/playhead constraint rejects concurrent writes.
        $events = [];
        foreach ($account->getRecordedEvents() as $event) {
            if (!$event instanceof \Codefy\Domain\EventSourcing\DomainEvent) {
                throw new \LogicException('Invalid financial event.');
            }
            if (isset($event->payload()['actor_id']) && $event->payload()['actor_id'] !== $context->actor->value) {
                throw new \InvalidArgumentException('The financial event and trusted actor must agree.');
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
            $stream = new StreamReference($account->aggregateId(), 'student_account');
            foreach ($events as $event) {
                // Preserve framework event IDs, event shapes and per-event retry receipts in the same journal.
                $this->history->append(
                    $stream,
                    new EntityId((string) $event->eventId()),
                    $event->playhead() - 1,
                    $context,
                    [$event]
                );
            }
            new \Infrastructure\Projection\SqlAccountBalanceProjection(
                new \Infrastructure\Persistence\SqlRecords($this->db)
            )->advance($account, $events);
        });
        $account->clearRecordedEvents();
    }
}
