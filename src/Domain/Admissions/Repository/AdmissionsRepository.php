<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\AdmissionApplication;
use Domain\Person\Person;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;

interface AdmissionsRepository
{
    public function addPerson(Person $person): void;

    public function person(EntityId $id): Person;

    public function personByInstitutionalId(InstitutionalId $id): Person;

    public function saveInstitutionalId(Person $person, int $expectedVersion): void;

    public function addApplication(AdmissionApplication $application): void;

    public function application(EntityId $id): AdmissionApplication;

    public function saveDecision(AdmissionApplication $application, int $expectedVersion): void;

    /** Atomically mark the accepted application matriculated and create its student and program assignment. */
    public function matriculate(AdmissionApplication $application, Student $student): \Domain\Student\StudentProgram;

    public function student(EntityId $id): Student;
}
