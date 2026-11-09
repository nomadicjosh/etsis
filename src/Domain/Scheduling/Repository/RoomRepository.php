<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\Room;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;

interface RoomRepository
{
    public function get(EntityId $id): Room;
    public function save(Room $room): void;
    /** @return list<array<string, mixed>> */
    public function agenda(EntityId $room, TimeSlot $window, ?EntityId $after, int $limit): array;
}
