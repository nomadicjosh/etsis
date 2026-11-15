<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class BookingOccurrence
{
    public function __construct(
        public EntityId $id,
        public TimeSlot $time,
        public ?EntityId $cancelledBy = null,
        public ?DateTimeImmutable $cancelledAt = null,
    ) {
        if (($cancelledBy === null) !== ($cancelledAt === null)) {
            throw new InvalidArgumentException('A meeting cancellation requires both actor and time.');
        }
    }

    public static function plan(TimeSlot $time): self
    {
        return new self(EntityId::generate(), $time);
    }

    public function cancel(EntityId $actor, DateTimeImmutable $at): self
    {
        return $this->cancelledAt !== null ? $this : new self($this->id, $this->time, $actor, $at);
    }
}
