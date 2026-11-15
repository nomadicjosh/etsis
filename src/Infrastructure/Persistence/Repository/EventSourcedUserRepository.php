<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\Aggregate\AggregateId;
use Codefy\Domain\Aggregate\AggregateNotFoundException;
use Codefy\Domain\Aggregate\RecordsEvents;
use Codefy\Domain\EventSourcing\CorruptEventStreamException;
use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Codefy\Traits\EventSourcedRepositoryAware;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\Service\UserProjection;
use Domain\User\User;
use Exception;
use Qubus\Expressive\Database;

final class EventSourcedUserRepository implements UserAggregateRepository
{
    use EventSourcedRepositoryAware;

    public function __construct(
        protected TransactionalEventStore $eventStore,
        protected UserProjection $projection,
        private readonly Database $db,
        private readonly \Domain\Shared\EventSourcing\HistoricalEventStore $history,
    ) {
    }

    /**
     * @throws AggregateNotFoundException
     * @throws CorruptEventStreamException
     */
    public function loadAggregateRoot(AggregateId $aggregateId): RecordsEvents
    {
        $cached = $this->retrieveFromIdentityMap($aggregateId);
        if ($cached instanceof RecordsEvents) {
            return $cached;
        }

        $aggregateHistory = $this->eventStore->getAggregateHistoryFor(aggregateId: $aggregateId);
        $eventSourcedAggregate = User::reconstituteFromEventStream(
            aggregateHistory: $aggregateHistory
        );

        $this->attachToIdentityMap($eventSourcedAggregate);

        return $eventSourcedAggregate;
    }

    /**
     * @throws Exception
     */
    public function saveAggregateRoot(RecordsEvents $aggregate): void
    {
        $this->save($aggregate, null);
    }
    public function saveProfile(User $user, \Domain\Shared\EventSourcing\EventContext $context): void
    {
        if (
            $context->origin !== 'command' || $context->actor === null || $context->effectiveAt === null ||
            $context->correlation === null
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $this->save($user, $context);
    }
    private function save(RecordsEvents $aggregate, ?\Domain\Shared\EventSourcing\EventContext $context): void
    {
        $this->attachToIdentityMap($aggregate);

        /** @var list<DomainEvent> $events */
        $events = array_values(iterator_to_array($aggregate->getRecordedEvents()));

        try {
            \Infrastructure\Persistence\PortableTransactions::run($this->db, function () use ($events, $context): void {
                if ($events === []) {
                    return;
                }
                if ($context === null) {
                    $transaction = $this->eventStore->commit(...$events);
                    $this->projection->project(...$transaction->committedEvents);
                } else {
                    $this->history->append(
                        new \Domain\Shared\EventSourcing\StreamReference($events[0]->aggregateId(), 'user'),
                        $context->correlation ?? throw new \Domain\Shared\EventSourcing\CorruptHistoryException(),
                        $events[0]->playhead() - 1,
                        $context,
                        $events
                    );
                    $this->projection->project(...$events);
                }
            });
            $aggregate->clearRecordedEvents();
        } finally {
            // A failed projection must not leave an uncommitted account in the identity map.
            $this->removeFromIdentityMap($aggregate);
        }
    }
}
