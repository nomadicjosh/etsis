<?php

declare(strict_types=1);

namespace Domain\Finance\Dto;

use Domain\Finance\StudentAccount;
use Domain\Person\Person;

final readonly class StudentAccountProfile
{
    public function __construct(public StudentAccount $account, public Person $person)
    {
    }
}
