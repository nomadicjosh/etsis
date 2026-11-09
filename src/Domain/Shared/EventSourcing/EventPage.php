<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

final readonly class EventPage
{
    /** @param list<StoredEvent> $events */
    public function __construct(public array $events, public ?string $nextCursor, public int $throughVersion)
    {
    }
}
