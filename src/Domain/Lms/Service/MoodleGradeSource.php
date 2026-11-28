<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\Repository\MoodleSectionRepository;
use Domain\Person\Service\ProfileLedger;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Internal verified source reader. Calling intake/review services own authorization. */
final readonly class MoodleGradeSource
{
    public function __construct(
        private MoodleBindingRepository $bindings,
        private MoodleSectionRepository $sections,
        private MoodlePersonLinkRepository $links,
        private RegistrationRepository $registration,
        private EnrollmentLedger $enrollments,
        private AcademicCreditRepository $records,
        private InstitutionalCreditLedger $credits,
        private AdmissionsRepository $admissions,
        private ProfileLedger $profiles,
        private UnitOfWork $transactions,
    ) {
    }

    public static function hash(array $value): string
    {
        return hash("sha256", json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function scope(EntityId $section): array
    {
        $binding =
        $this->bindings->current() ??
        throw new RecordNotFoundException(
            trans("Approved Moodle installation missing."),
        );
        $link =
        $this->sections->link($binding->id, $section) ??
        throw new RecordNotFoundException(
            trans("Approved Moodle section link missing."),
        );
        $offering = $this->sections->section($section);
        return [
            "binding" => $binding,
            "link" => $link,
            "section" => $offering,
            "hash" => self::hash([
                $binding->describe(),
                $link["id"],
                $link["moodle_course_id"],
                $offering["hash"],
            ]),
        ];
    }

    public function person(EntityId $student): \Domain\Person\Person
    {
        return $this->admissions->person(
            $this->admissions->student($student)->personId,
        );
    }

    public function capture(
        EntityId $section,
        EntityId $student,
        EntityId $enrollment,
    ): array {
        return $this->transactions->run(function () use (
            $section,
            $student,
            $enrollment,
        ): array {
            $person = $this->admissions->person(
                $this->admissions->student($student)->personId,
            );
            $this->registration->lockPerson($person->id);
            $this->registration->lockStudent($student);
            $this->registration->lockSection($section);
            $scope = $this->scope($section);
            $active = $this->registration->enrollment($student, $section);
            if (
                $active === null ||
                $active->id->value !== $enrollment->value ||
                !$active->status->isEnrolled() ||
                $active->basis->value !== "standard"
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "The original academic enrollment is no longer eligible.",
                    ),
                );
            }
            $budget = new HistoryReadBudget();
            $this->enrollments->verifyExisting(
                $active,
                $this->registration->section($section),
                $budget,
            );
            $row =
                $this->records->forEnrollment($enrollment) ??
                throw new RecordNotFoundException(
                    trans("Academic record missing."),
                );
            $academic = $this->credits->auditFacts(
                new EntityId($row["id"]),
                $student,
                $budget,
            );
            if (
                !in_array($academic["credit"]["status"], ["A", "N"], true) ||
                !$academic["history_recorded"] ||
                $academic["earlier_history_unknown"] ||
                !empty($academic["continuing_education_only"])
            ) {
                throw new AccessDeniedException(
                    trans("Verified academic source required."),
                );
            }
            $personLink =
                $this->links->forPerson($scope["binding"]->id, $person->id) ??
                throw new RecordNotFoundException(
                    trans("Approved Moodle person link missing."),
                );
            $core = $this->profiles->coreReference($person, $budget);
            $route = self::hash([
                $scope["hash"],
                $student->value,
                $enrollment->value,
                $active->version,
                $core,
                $person->ownerUserId?->value,
                $personLink->describe(),
            ]);
            return [
                "binding" => $scope["binding"],
                "scope_hash" => $scope["hash"],
                "route_hash" => $route,
                "source_hash" => self::hash([$route, $academic]),
                "student_id" => $student->value,
                "person_id" => $person->id->value,
                "enrollment_id" => $enrollment->value,
                "section_id" => $section->value,
                "binding_id" => $scope["binding"]->id->value,
                "section_link_id" => $scope["link"]["id"],
                "person_link_id" => $personLink->id->value,
                "moodle_course_id" => $scope["link"]["moodle_course_id"],
                "moodle_user_id" => $personLink->external->value,
                "credit" => $academic["credit"],
                "identity" => [
                    "name" => trim(
                        $person->givenName . " " . $person->familyName,
                    ),
                    "institutional_id" => $person->institutionalId->value,
                ],
            ];
        });
    }
}
