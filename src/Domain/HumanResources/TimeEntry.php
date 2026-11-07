<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

final readonly class TimeEntry
{
    public function __construct(
        public EntityId $id,
        public EntityId $employeeId,
        public TimeSlot $work,
        public string $status = 'pending',
        public ?EntityId $approvedBy = null,
        public int $version = 0,
    ) {
        if (
            $work->endsAt->getTimestamp() - $work->startsAt->getTimestamp() > 86400
            || !in_array($status, ['pending', 'approved', 'rejected'], true)
        ) {
            throw new InvalidArgumentException('A time entry must have a valid state and span at most 24 hours.');
        }
    }

    public function decide(bool $approve, EntityId $actor, ?EntityId $owner): self
    {
        if ($this->status !== 'pending' || $actor->value === $owner?->value) {
            throw new DomainException('Only pending time entries can be approved by another authorized person.');
        }

        return new self(
            $this->id,
            $this->employeeId,
            $this->work,
            $approve ? 'approved' : 'rejected',
            $actor,
            $this->version + 1
        );
    }
}
