<?php

declare(strict_types=1);

namespace Domain\Student\Dto;

use Domain\Person\Person;
use Domain\Student\Student;

final readonly class StudentProfile
{
    public function __construct(public Student $student, public Person $person)
    {
    }
}
