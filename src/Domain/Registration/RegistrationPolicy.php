<?php

declare(strict_types=1);

namespace Domain\Registration;

use DateTimeImmutable;
use Domain\Student\Restriction;
use Domain\Student\Student;
use DomainException;

final class RegistrationPolicy
{
    /** @param list<Restriction> $restrictions */
    public function assertEligible(
        Student $student,
        array $restrictions,
        DateTimeImmutable $on,
        bool $byStaff,
        bool $registrationOpen,
        bool $sectionAllowsWebRegistration,
        int $enrolledCourses,
        int $courseLimit,
        bool $prerequisitesMet,
    ): void {
        if (!$student->active || !$prerequisitesMet) {
            throw new DomainException('An active student meeting course prerequisites is required.');
        }
        foreach ($restrictions as $restriction) {
            if ($restriction->personId->value !== $student->personId->value) {
                throw new DomainException('Restrictions must belong to the student.');
            }
            if ($restriction->blocksRegistration($on, $byStaff)) {
                throw new DomainException('An active restriction prevents registration.');
            }
        }
        if (!$byStaff && (!$registrationOpen || !$sectionAllowsWebRegistration)) {
            throw new DomainException('Self-service registration is closed.');
        }
        if ($enrolledCourses < 0 || $courseLimit < 1 || $enrolledCourses >= $courseLimit) {
            throw new DomainException('The term course limit has been reached.');
        }
    }
}
