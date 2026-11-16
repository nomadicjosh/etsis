<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Codefy\Domain\EventSourcing\DomainEvent;
use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Student\Event\PersonRestrictionsWereRecorded;
use Domain\Student\PersonRestrictions;
use Domain\Student\Repository\RestrictionRepository;
use Domain\Student\Restriction;
use Domain\Student\ValueObject\PersonRestrictionsId;

/** Internal port: callers authorize, share the person lock and own the atomic transaction. */
final readonly class RestrictionLedger
{
    public function __construct(
        private RestrictionRepository $records,
        private HistoricalEventStore $history,
        private Clock $clock,
    ) {
    }

    /** @return array{root:PersonRestrictions,repeat:bool} */
    public function open(EntityId $person, EntityId $operation, EntityId $actor, string $intent): array
    {
        $result = $this->load($person, $operation);
        $receipt = $result['receipt'];
        if (
            $receipt !== null
            && ($receipt->context->actor?->value !== $actor->value
                || $receipt->event->payload()['intent'] !== $intent)
        ) {
            throw new ConcurrentModificationException('The operation ID belongs to another request.');
        }
        return ['root' => $result['root'], 'repeat' => $receipt !== null];
    }

    public function verify(EntityId $person): void
    {
        $this->load($person);
    }

    /** Called only for an accepted real decision; no history is invented during reads. */
    public function record(
        PersonRestrictions $root,
        EntityId $operation,
        EntityId $actor,
        string $action,
        Restriction $restriction,
        string $intent,
        #[\SensitiveParameter] string $reason,
    ): void {
        $effectiveDate = match ($action) {
            'placed' => $restriction->dates->start,
            'ended' => $restriction->dates->end ?? throw new \InvalidArgumentException('An end date is required.'),
            default => throw new \InvalidArgumentException('A valid hold decision is required.'),
        };
        $person = $restriction->personId;
        $expected = $root->playhead();
        if ($expected + ($expected === 0 ? 2 : 1) > PersonRestrictions::MAX_EVENTS) {
            throw new HistoryReadLimitException();
        }
        $events = [];
        if ($expected === 0) {
            $events[] = $this->event(
                $root,
                DerivedIdentity::from($operation, 'restriction.observation'),
                'observed',
                array_map(static fn (Restriction $r): array => $r->facts(), $this->current($person)),
                null
            );
        }
        if ($action === 'placed' && count($root->restrictions()) >= PersonRestrictions::MAX_RESTRICTIONS) {
            throw new HistoryReadLimitException();
        }
        $events[] = $this->event($root, $operation, $action, [$restriction->facts()], $intent);
        // The baseline is today's observation, not a historical placement or a claimed original author.
        // Each event has its own effective context; the shared transaction rolls both back on failure.
        foreach ($events as $event) {
            $observation = $event->payload()['action'] === 'observed';
            $effective = $observation ? $this->clock->now()
            : new DateTimeImmutable(
                $effectiveDate->format('Y-m-d'),
                $this->clock->now()->getTimezone()
            );
            $this->history->append(
                $this->stream($person),
                new EntityId($event->payload()['operation_id']),
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    $effective,
                    $operation,
                    $event->payload()['previous_operation_id'] === null ? null
                        : new EntityId($event->payload()['previous_operation_id']),
                    $observation ? 'Current restrictions observed before a real decision; earlier history is unknown.'
                    : $reason
                ),
                [$event]
            );
        }
    }

    /** Curated, paged staff history; encrypted reasons, notes and raw envelopes stay private.
     * @return array<string,mixed> */
    public function timeline(EntityId $person, EntityId $restriction, ?string $cursor = null): array
    {
        $this->verify($person);
        $page = $this->history->page($this->stream($person), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            foreach ($p['facts'] as $facts) {
                if ($facts['id'] === $restriction->value) {
                    $items[] = ['action' => $p['action'], 'facts' => $facts,
                        'recorded_at' => $stored->recordedAt->format(DATE_ATOM),
                        'effective_on' => $stored->context->effectiveAt?->format('Y-m-d')];
                }
            }
        }
        return ['items' => $items, 'next_cursor' => $page->nextCursor];
    }

    /** @return array{root:PersonRestrictions,receipt:?\Domain\Shared\EventSourcing\StoredEvent} */
    private function load(EntityId $person, ?EntityId $operation = null): array
    {
        $root = PersonRestrictions::root(new PersonRestrictionsId($person->value));
        $cursor = null;
        $receipt = null;
        do {
            $page = $this->history->page($this->stream($person), $cursor, 50);
            if ($page->throughVersion > PersonRestrictions::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($operation !== null && $stored->event->payload()['operation_id'] === $operation->value) {
                    if ($receipt !== null) {
                        throw new CorruptHistoryException();
                    }
                    $receipt = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $current = $this->current($person);
        if ($root->playhead() > 0) {
            $expected = array_map(static fn (Restriction $r): array => $r->facts(), $root->restrictions());
            $observed = [];
            foreach ($current as $hold) {
                $observed[$hold->id->value] = $hold->facts();
            }
            ksort($expected);
            ksort($observed);
            if ($expected !== $observed) {
                throw new CorruptHistoryException();
            }
        }
        return ['root' => $root, 'receipt' => $receipt];
    }

    /** @return list<Restriction> */
    private function current(EntityId $person): array
    {
        $rows = $this->records->page($person, null, PersonRestrictions::MAX_RESTRICTIONS + 1);
        if (count($rows) > PersonRestrictions::MAX_RESTRICTIONS) {
            throw new HistoryReadLimitException();
        }
        return array_map(static function (array $row): Restriction {
            return Restriction::fromFacts(['id' => $row['id'], 'person_id' => $row['person_id'],
                'code' => $row['code'], 'severity' => (int) $row['severity'],
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
                'blocks_staff' => (bool) $row['blocks_staff']]);
        }, $rows);
    }

    /** @param list<array<string,mixed>> $facts */
    private function event(
        PersonRestrictions $root,
        EntityId $operation,
        string $action,
        array $facts,
        ?string $intent,
    ): DomainEvent {
        $event = PersonRestrictionsWereRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => $root->lastOperation(),
            'action' => $action, 'facts' => $facts, 'intent' => $intent,
        ], ['__aggregate_type' => 'person_restrictions'])->withPlayhead($root->playhead() + 1);
        $root->acceptHistorical($event);
        return $event;
    }

    private function stream(EntityId $person): StreamReference
    {
        return new StreamReference(new PersonRestrictionsId($person->value), 'person_restrictions');
    }
}
