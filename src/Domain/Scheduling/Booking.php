<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

final readonly class Booking
{
    /** @param list<TimeSlot> $slots */
    public function __construct(
        public EntityId $id,
        public EntityId $roomId,
        public EntityId $ownerUserId,
        public string $title,
        public array $slots,
        public string $status = 'requested',
        public int $version = 0,
    ) {
        if (
            trim($title) === '' || mb_strlen($title) > 191 || count($slots) < 1 || count($slots) > 366
            || !in_array($status, ['requested', 'approved', 'cancelled'], true)
        ) {
            throw new InvalidArgumentException('A booking requires a title, bounded occurrences and a valid state.');
        }
        foreach ($slots as $i => $slot) {
            foreach (array_slice($slots, $i + 1) as $other) {
                if ($slot->overlaps($other)) {
                    throw new DomainException('Booking occurrences cannot overlap each other.');
                }
            }
        }
    }

    public function transition(string $status): self
    {
        if (
            ($status === 'approved' && $this->status !== 'requested')
            || ($status === 'cancelled' && $this->status === 'cancelled')
            || !in_array($status, ['approved', 'cancelled'], true)
        ) {
            throw new DomainException('Invalid booking transition.');
        }

        return new self(
            $this->id,
            $this->roomId,
            $this->ownerUserId,
            $this->title,
            $this->slots,
            $status,
            $this->version + 1
        );
    }
}
