<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\Employee;
use Domain\HumanResources\TimeEntry;
use Domain\Shared\ValueObject\EntityId;

interface TimeRepository
{
    public function addEmployee(EntityId $id, EntityId $person, string $jobTitle): void;
    public function employee(EntityId $id): Employee;
    public function employeePerson(EntityId $employee): EntityId;
    public function lockEmployee(EntityId $employee): void;
    public function add(TimeEntry $entry): void;
    public function get(EntityId $id): TimeEntry;
    public function save(TimeEntry $entry, int $expectedVersion): void;
}
