<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Codefy\Domain\EventSourcing\DomainEvent;

/** A synchronous read-model consumer. The journal transaction encloses every projection write. */
interface HistoricalProjection
{
    /** @param list<DomainEvent> $events */
    public function project(
        StreamReference $stream,
        int $previousVersion,
        #[\SensitiveParameter] array $events,
        string $previousHash,
        string $headHash,
    ): void;
}
