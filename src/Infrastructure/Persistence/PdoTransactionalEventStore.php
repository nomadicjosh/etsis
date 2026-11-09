<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Codefy\Domain\Aggregate\AggregateId;
use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\EventSourcing\DomainEvents;
use Codefy\Domain\EventSourcing\EventStoreTransaction;
use Codefy\Domain\EventSourcing\EventStream;
use Codefy\Domain\EventSourcing\Transactional;
use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Codefy\Domain\EventSourcing\TransactionId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;

/** Framework compatibility port over the one protected event journal. No independent SQL writes. */
final readonly class PdoTransactionalEventStore implements TransactionalEventStore
{
    public function __construct(
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private HistoricalSchemaRegistry $schemas,
    ) {
    }

    public function append(
        #[\SensitiveParameter] DomainEvent $event,
        TransactionId $transactionId,
    ): void {
        $this->transactions->run(function () use (
            $event,
            $transactionId,
        ): void {
            $actor = $event->payload()["actor_id"] ?? null;
            // This interface carries no authenticated command context. Preserve that limitation explicitly.
            $context = new EventContext(
                $actor === null ? null : new EntityId($actor),
                null,
                null,
                origin: "framework",
                sourceTransactionId: (string) $transactionId,
            );
            $this->history->append(
                $this->schemas->streamFor($event->aggregateId()),
                new EntityId((string) $event->eventId()),
                $event->playhead() - 1,
                $context,
                [$event],
            );
        });
    }

    public function commit(
        #[\SensitiveParameter] DomainEvent ...$events,
    ): Transactional {
        $id = new TransactionId();
        $this->transactions->run(function () use ($events, $id): void {
            foreach ($events as $event) {
                $this->append($event, $id);
            }
        });
        return new EventStoreTransaction(
            $id,
            DomainEvents::fromArray($events),
            $events,
        );
    }

    public function getAggregateHistoryFor(
        AggregateId $aggregateId,
    ): EventStream {
        return $this->loadFromPlayhead($aggregateId, 1);
    }

    public function loadFromPlayhead(
        AggregateId $aggregateId,
        int $playhead,
    ): EventStream {
        if ($playhead < 1) {
            throw new \InvalidArgumentException(
                "An event playhead starts at one.",
            );
        }
        $stream = $this->schemas->streamFor($aggregateId);
        // Read in bounded pages and verify the prefix too. The framework's EventStream still materializes the result.
        $events = [];
        $cursor = null;
        do {
            $page = $this->history->page($stream, $cursor, 100);
            foreach ($page->events as $stored) {
                if ($stored->event->playhead() >= $playhead) {
                    $events[] = $stored->event;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return new EventStream($aggregateId, $events);
    }
}
