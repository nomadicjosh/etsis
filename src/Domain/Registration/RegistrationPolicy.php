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
        $issues = $this->issues(
            $student,
            $restrictions,
            $on,
            $byStaff,
            $registrationOpen,
            $sectionAllowsWebRegistration,
            $enrolledCourses,
            $courseLimit,
            $prerequisitesMet,
        );
        if ($issues !== []) {
            throw new DomainException($issues[0]);
        }
    }

    /** @param list<Restriction> $restrictions
     * @return list<string>
     */
    public function issues(
        Student $student,
        array $restrictions,
        DateTimeImmutable $on,
        bool $byStaff,
        bool $registrationOpen,
        bool $sectionAllowsWebRegistration,
        int $enrolledCourses,
        int $courseLimit,
        bool $prerequisitesMet,
    ): array {
        $issues = [];
        if (!$student->active || !$prerequisitesMet) {
            $issues[] = "An active student meeting course prerequisites is required.";
        }
        foreach ($restrictions as $restriction) {
            if ($restriction->personId->value !== $student->personId->value) {
                throw new DomainException("Restrictions must belong to the student.");
            }
            if ($restriction->blocksRegistration($on, $byStaff)) {
                $issues[] = "An active restriction prevents registration.";
            }
        }
        if (!$byStaff && (!$registrationOpen || !$sectionAllowsWebRegistration)) {
            $issues[] = "Self-service registration is closed.";
        }
        if ($enrolledCourses < 0 || $courseLimit < 1 || $enrolledCourses >= $courseLimit) {
            $issues[] = "The term course limit has been reached.";
        }
        return array_values(array_unique($issues));
    }
}
