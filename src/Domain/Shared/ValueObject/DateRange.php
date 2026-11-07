<?php

declare(strict_types=1);

namespace Domain\Shared\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public function __construct(public DateTimeImmutable $start, public ?DateTimeImmutable $end = null)
    {
        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException('The end must follow the start.');
        }
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->start && ($this->end === null || $date <= $this->end);
    }
}
