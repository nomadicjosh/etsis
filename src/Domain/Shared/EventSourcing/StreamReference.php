<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use Codefy\Domain\Aggregate\AggregateId;
use InvalidArgumentException;

final readonly class StreamReference
{
    public function __construct(public AggregateId $aggregateId, public string $type)
    {
        if (
            preg_match('~^[A-Za-z][A-Za-z0-9_.:\\\\-]{0,190}$~D', $type) !== 1 ||
            strlen((string) $aggregateId) > 36 ||
            (string) $aggregateId === ""
        ) {
            throw new InvalidArgumentException("A valid historical stream reference is required.");
        }
    }
}
