<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DomainException;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\RegistrationPolicy;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use JsonException;

final readonly class RegistrationService
{
    public function __construct(
        private RegistrationRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private RegistrationBilling $billing,
        private AuditTrail $audit,
        private Clock $clock,
        private CourseDropService $drops,
        private \Domain\AcademicRecord\Service\InstitutionalCreditLedger $credits,
        private EnrollmentLedger $enrollments,
        private \Domain\Student\Service\StudentActivityLedger $activity,
        private \Domain\Student\Service\StudentPrimaryProgramLedger $primaryPrograms,
    ) {
    }

    public function register(EntityId $studentId, EntityId $sectionId, bool $byStaff = false): Enrollment
    {
        $actor = $this->access->authenticatedUserId();
        $initialStudent = $this->admissions->student($studentId);
        $owner = $this->admissions->person($initialStudent->personId)->ownerUserId;
        $this->access->requireOwnerOrPermission($owner, "sis:registration:manage");
        if ($byStaff) {
            $this->access->requirePermission("sis:registration:manage");
        }

        return $this->transactions->run(function () use (
            $studentId,
            $sectionId,
            $byStaff,
            $actor,
            $initialStudent,
        ): Enrollment {
            // Acquire all eligibility locks before consistent reads: student, person holds, then section seats.
            $this->repository->lockStudent($studentId);
            $this->repository->lockPerson($initialStudent->personId);
            $this->repository->lockSection($sectionId);
            $student = $this->admissions->student($studentId);
            $person = $this->admissions->person($student->personId);
            $currentActor = $this->access->requireOwnerOrPermission(
                $person->ownerUserId,
                "sis:registration:manage",
            );
            if ($currentActor->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException("The registration actor changed.");
            }
            $byStaff = $byStaff || $person->ownerUserId?->value !== $actor->value;
            if ($byStaff) {
                $this->access->requirePermission("sis:registration:manage");
            }
            $this->activity->verify($student);
            $this->primaryPrograms->verify($student);
            $section = $this->repository->section($sectionId);
            $course = $this->repository->courseForSection($section);
            $window = $this->repository->window($section->termId);
            $existing = $this->repository->enrollment($studentId, $sectionId);
            if ($existing !== null) {
                if ($existing->status->isEnrolled()) {
                    $this->enrollments->verifyExisting($existing, $section);
                    return $existing;
                }
                throw new DomainException(
                    "A closed enrollment requires registrar review before re-enrollment.",
                );
            }
            if (
                !$section->active ||
                !$course->active ||
                $this->repository->sectionCount($sectionId) >= $section->capacity
            ) {
                throw new DomainException("The section is inactive or full.");
            }
            $now = $this->clock->now();
            $blocking = $this->repository->blockingRestriction($student->personId, $now, $byStaff);
            $required = $course->publishedPrerequisites ?? $this->repository->requiredCourses($course->id);
            $coursesBefore = $this->repository->enrolledCount($studentId, $section->termId);
            $prerequisitesMet = $this->repository->prerequisitesMet(
                $studentId,
                $section->courseId,
                $required,
            );
            new RegistrationPolicy()->assertEligible(
                $student,
                $blocking === null ? [] : [$blocking],
                $now,
                $byStaff,
                $window->isOpen($now),
                $section->webRegistration,
                $coursesBefore,
                $window->courseLimit,
                $prerequisitesMet,
            );
            $enrollment = new Enrollment(
                EntityId::generate(),
                $studentId,
                $sectionId,
                $section->termId,
                $byStaff ? RegistrationStatus::StaffRegistered : RegistrationStatus::SelfRegistered,
                $now,
                $now,
            );
            $this->enrollments->accept(
                $enrollment,
                $section,
                $course,
                [
                    "student_active" => $student->active,
                    "by_staff" => $byStaff,
                    "self_service_open" => $window->isOpen($now),
                    "courses_before" => $coursesBefore,
                    "course_limit" => $window->courseLimit,
                    "prerequisites_met" => $prerequisitesMet,
                    "required_course_ids" => $required,
                    "opens_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                        $window->dates->start,
                    ),
                    "closes_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                        $window->dates->end ?? throw new \LogicException("A registration window must end."),
                    ),
                ],
                $actor,
            );
            $this->credits->open($enrollment, $course, $actor);
            $this->billing->charge($studentId, $section->termId, $enrollment->id, $section->fee, $actor);
            $this->audit->record($actor, "registration.created", $enrollment->id);

            return $enrollment;
        });
    }

    public function drop(EntityId $studentId, EntityId $sectionId): Enrollment
    {
        return $this->drops->drop($studentId, $sectionId);
    }

    /** @return array<string, mixed> */
    public function reviewDrop(EntityId $student, EntityId $section): array
    {
        return $this->drops->review($student, $section);
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function dropReviewed(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        EntityId $operation,
        string $revision,
        string $effective,
    ): array {
        return $this->drops->commit(
            $student,
            $section,
            $enrollment,
            $version,
            $operation,
            $revision,
            $effective,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function dropReceipt(EntityId $operation): array
    {
        return $this->drops->receipt($operation);
    }
}
