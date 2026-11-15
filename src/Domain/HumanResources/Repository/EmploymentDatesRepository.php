<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\Shared\ValueObject\EntityId;

interface EmploymentDatesRepository
{
    public function find(EntityId $employee): ?EmploymentDates;
    public function save(EmploymentDates $dates, int $expectedVersion): void;
}
