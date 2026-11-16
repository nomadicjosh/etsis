<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Aggregate\AggregateId;
use Codefy\Domain\EventSourcing\EventStream;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use LogicException;

/** Repositories enclose these appends and their projections in the same database transaction. */
final readonly class FinancialEventWriter
{
    public function __construct(private HistoricalEventStore $history, private HistoricalSchemaRegistry $schemas)
    {
    }

    public function append(
        #[\SensitiveParameter] EventSourcedAggregate $aggregate,
        #[\SensitiveParameter] EventContext $context,
    ): void {
        if ($context->origin !== 'command' || $context->actor === null || $context->reason === null) {
            throw new InvalidArgumentException('An attributed financial command and protected reason are required.');
        }
        $stream = $this->schemas->streamFor($aggregate->aggregateId());
        if (!in_array($stream->type, ['student_account', 'billing_rule', 'journal'], true)) {
            throw new LogicException('A financial event stream is required.');
        }
        foreach ($aggregate->getRecordedEvents() as $event) {
            if (!$event instanceof DomainEvent) {
                throw new LogicException('Invalid financial event.');
            }
            if (isset($event->payload()['actor_id']) && $event->payload()['actor_id'] !== $context->actor->value) {
                throw new InvalidArgumentException('The financial event and trusted actor must agree.');
            }
            // Keep original Codefy identities, playheads, shapes and per-event receipts in the same journal.
            $this->history->append(
                $stream,
                new EntityId((string) $event->eventId()),
                $event->playhead() - 1,
                $context,
                [$event],
            );
        }
    }

    /** Selected command/review reads share one allowance through the same protected journal. */
    public function readForWrite(AggregateId $id, HistoryReadBudget $budget): EventStream
    {
        $stream = $this->schemas->streamFor($id);
        if (!in_array($stream->type, ['student_account', 'billing_rule', 'journal'], true)) {
            throw new LogicException('A financial event stream is required.');
        }
        $events = [];
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $events[] = $stored->event;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return new EventStream($id, $events);
    }
}
