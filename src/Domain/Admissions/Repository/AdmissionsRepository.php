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

    public function lockPerson(EntityId $id): void;
    public function lockApplication(EntityId $id): void;
    public function studentForPerson(EntityId $person): ?EntityId;
    /** Projection write only: the authorized matriculation service owns locks and the complete transaction. */
    public function projectMatriculation(
        AdmissionApplication $application,
        Student $student,
        \Domain\Student\StudentProgram $assignment,
    ): void;

    public function student(EntityId $id): Student;
}
