<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\EmployeePosition;
use Domain\Shared\ValueObject\EntityId;

interface PositionRepository
{
    public function find(EntityId $id): ?EmployeePosition;
    /** @return list<EmployeePosition> */
    public function page(EntityId $employee, ?EntityId $after = null): array;
    public function lock(EntityId $id): void;
    public function save(EmployeePosition $position, int $expectedVersion): void;
}
