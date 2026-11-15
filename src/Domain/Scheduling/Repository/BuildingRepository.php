<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\Building;
use Domain\Shared\ValueObject\EntityId;

interface BuildingRepository
{
    public function get(EntityId $id): Building;
    public function lock(EntityId $id): void;
    public function add(Building $building): void;
    public function save(Building $building): void;
}
