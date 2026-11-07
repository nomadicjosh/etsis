<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\Booking;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;

interface BookingRepository
{
    public function addRoom(EntityId $id, string $code, int $capacity): void;
    public function lockRoom(EntityId $id): void;
    public function add(Booking $booking): void;
    public function get(EntityId $id): Booking;
    public function save(Booking $booking, int $expectedVersion): void;
    public function conflicts(EntityId $room, TimeSlot $slot, ?EntityId $excluding = null): bool;
}
