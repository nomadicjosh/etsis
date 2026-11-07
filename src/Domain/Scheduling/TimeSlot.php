<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TimeSlot
{
    public function __construct(public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt)
    {
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('A booking must end after it starts.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }
}
