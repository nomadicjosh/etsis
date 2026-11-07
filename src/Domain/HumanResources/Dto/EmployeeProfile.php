<?php

declare(strict_types=1);

namespace Domain\HumanResources\Dto;

use Domain\HumanResources\Employee;
use Domain\Person\Person;

final readonly class EmployeeProfile
{
    public function __construct(public Employee $employee, public Person $person)
    {
    }
}
