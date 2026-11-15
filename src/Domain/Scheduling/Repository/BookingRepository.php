<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\Booking;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;

interface BookingRepository
{
    public function lockRoom(EntityId $id): void;
    public function add(Booking $booking): void;
    public function get(EntityId $id): Booking;
    public function save(Booking $booking, int $expectedVersion): void;
    public function cancelOccurrence(Booking $booking, EntityId $occurrence, int $expectedVersion): void;
    public function replaceOccurrence(
        Booking $original,
        EntityId $occurrence,
        Booking $replacement,
        int $expectedVersion
    ): void;
    /** @return array{source_booking_id:?string,source_occurrence_id:?string,replacements:array<string,string>} */
    public function links(EntityId $booking): array;
    public function conflictsExceptOccurrence(EntityId $room, TimeSlot $slot, EntityId $excluding): bool;
    public function conflicts(EntityId $room, TimeSlot $slot, ?EntityId $excluding = null): bool;
}
