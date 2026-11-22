<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\ValueObject\PrimaryPosition;
use Domain\Shared\ValueObject\EntityId;

interface PrimaryPositionRepository
{
    public function find(EntityId $employee): ?PrimaryPosition;
    public function save(PrimaryPosition $designation, int $expectedVersion): void;
}
