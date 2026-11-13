<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\ValueObject\EntityId;

/** Internal persistence port. Calling domain services must authorize every read and command. */
interface HistoricalEventStore
{
    /**
     * Expected version is zero for a new stream. Codefy playheads start at one.
     * @param list<DomainEvent> $events
     * @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>}
     */
    public function append(
        StreamReference $stream,
        EntityId $operation,
        int $expectedVersion,
        #[\SensitiveParameter] EventContext $context,
        #[\SensitiveParameter] array $events,
    ): array;

    /** A cursor freezes the first page's committed head; subsequent appends do not enter that replay. */
    public function page(StreamReference $stream, ?string $cursor = null, int $limit = 50): EventPage;

    /** Verify and return the committed head event, without reconstructing the aggregate or scanning earlier events.
     * Only suitable for checks whose required state is self-contained in the latest event.
     */
    public function latest(StreamReference $stream): ?StoredEvent;

    /** Read-only operator check of one retry receipt and its bounded batch (1–50 events).
     * Returns the number of associated events verified; does not invoke aggregate handlers or projectors.
     */
    public function verifyOperation(EntityId $operation): int;
}
