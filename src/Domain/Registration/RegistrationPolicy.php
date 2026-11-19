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
        return array_column(
            array_filter(
                $this->checks(
                    $student,
                    $restrictions,
                    $on,
                    $byStaff,
                    $registrationOpen,
                    $sectionAllowsWebRegistration,
                    $enrolledCourses,
                    $courseLimit,
                    $prerequisitesMet,
                ),
                static fn(array $check): bool => $check["status"] === "blocked",
            ),
            "message",
        );
    }

    /** Curated readiness only; never include restriction codes, reasons, dates or source identities.
     * @param list<Restriction> $restrictions
     * @return list<array{code:string,label:string,status:string,message:string,next_step:?string}>
     */
    public function checks(
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
        $blocked = false;
        foreach ($restrictions as $restriction) {
            if ($restriction->personId->value !== $student->personId->value) {
                throw new DomainException("Restrictions must belong to the student.");
            }
            $blocked = $restriction->blocksRegistration($on, $byStaff) || $blocked;
        }
        $check = static fn(string $code, string $label, bool $met, string $message, string $next): array => [
            "code" => $code,
            "label" => $label,
            "status" => $met ? "met" : "blocked",
            "message" => $met ? "Check passed." : $message,
            "next_step" => $met ? null : $next,
        ];
        $checks = [
            $check(
                "student_activity",
                "Student activity",
                $student->active,
                "The student record is inactive.",
                "Ask the registrar to review the student's active status.",
            ),
            $check(
                "prerequisites",
                "Required coursework",
                $prerequisitesMet,
                "Required coursework has not been verified for this program pursuit.",
                "Review Required courses and ask the registrar about missing or earlier-program coursework.",
            ),
            $check(
                "restrictions",
                "Registration restrictions",
                !$blocked,
                "An active restriction prevents registration.",
                "Contact the institution's student services office to resolve the registration restriction.",
            ),
            $check(
                "registration_window",
                "Self-service dates",
                $registrationOpen,
                "Self-service registration is closed.",
                "Ask the registration office about available registration dates.",
            ),
            $check(
                "web_registration",
                "Section self-service",
                $sectionAllowsWebRegistration,
                "This section does not allow self-service registration.",
                "Ask the registration office about staff-assisted registration for this section.",
            ),
            $check(
                "course_limit",
                "Term course limit",
                $enrolledCourses >= 0 && $courseLimit > 0 && $enrolledCourses < $courseLimit,
                "The term course limit has been reached.",
                "Review the term schedule with the registration office before adding another course.",
            ),
        ];
        if ($byStaff) {
            foreach ($checks as &$entry) {
                if (in_array($entry["code"], ["registration_window", "web_registration"], true)) {
                    $entry["status"] = "not_applicable";
                    $entry["message"] =
                    "Self-service availability does not control authorized staff registration.";
                    $entry["next_step"] = null;
                }
            }
            unset($entry);
        }
        return $checks;
    }
}
