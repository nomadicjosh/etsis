<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DomainException;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\RegistrationPolicy;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
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
        private \Domain\AcademicRecord\Service\WorkPursuitService $pursuits,
        private PrerequisiteService $prerequisites,
        private RegistrationScheduleService $schedules,
        private CoRequisiteService $corequisites,
        private RegistrationWindowService $windows,
        private \Domain\Registration\Waitlist\WaitlistSeatGuard $waitlists,
        private GraduateRegistrationGate $graduate,
    ) {
    }

    public function register(
        EntityId $studentId,
        EntityId $sectionId,
        bool $byStaff = false,
        ?EntityId $assignment = null,
        ?\Domain\Shared\EventSourcing\HistoryReadBudget $budget = null,
        ?RegistrationScheduleRead $scheduleRead = null,
        ?\Domain\Finance\ValueObject\RegistrationChargeQuote $chargeQuote = null,
        ?RegistrationCourseRead $courseRead = null,
        ?CoRequisitePlan $corequisitePlan = null,
        ?\Domain\Registration\Waitlist\WaitlistBypass $waitlistBypass = null,
        ?\Domain\Shared\EventSourcing\HistoryReadBudget $waitlistBudget = null,
    ): Enrollment {
        $actor = $this->access->authenticatedUserId();
        $initialStudent = $this->admissions->student($studentId);
        $owner = $this->admissions->person($initialStudent->personId)
            ->ownerUserId;
        $this->authorize($actor, $owner, $byStaff);

        return $this->transactions->run(function () use (
            $studentId,
            $sectionId,
            $byStaff,
            $actor,
            $initialStudent,
            $assignment,
            $budget,
            $scheduleRead,
            $chargeQuote,
            $courseRead,
            $corequisitePlan,
            $waitlistBypass,
            $waitlistBudget,
        ): Enrollment {
            // Eligibility locks follow student, person, section and course order.
            $this->repository->lockStudent($studentId);
            $this->repository->lockPerson($initialStudent->personId);
            $this->repository->lockSection($sectionId);
            $section = $this->repository->section($sectionId);
            $this->repository->lockCourse($section->courseId);
            $student = $this->admissions->student($studentId);
            if (
                $student->personId->value !== $initialStudent->personId->value
            ) {
                throw new ConcurrentModificationException(
                    "The student identity changed. Reload before registering.",
                );
            }
            $person = $this->admissions->person($student->personId);
            $byStaff =
                $byStaff || $person->ownerUserId?->value !== $actor->value;
            $this->authorize($actor, $person->ownerUserId, $byStaff);
            $this->activity->verify($student);
            $this->primaryPrograms->verify($student);
            $existing = $this->repository->enrollment($studentId, $sectionId);
            if ($existing !== null) {
                if ($existing->status->isEnrolled()) {
                    $this->enrollments->verifyExisting($existing, $section);
                    $this->verifyAccess(
                        $actor,
                        $student,
                        $person->ownerUserId,
                        $byStaff,
                    );
                    return $existing;
                }
                throw new DomainException(
                    "A closed enrollment requires registrar review before re-enrollment.",
                );
            }
            if ($waitlistBypass !== null && !$byStaff) {
                throw new AccessDeniedException('Waitlist bypass requires staff registration.');
            }
            $waitlistClaim = $this->waitlists->claim(
                $sectionId,
                $studentId,
                $person->id,
                $waitlistBypass,
                $waitlistBudget,
            );
            $course = $this->repository->courseForSection($section);
            if ($section->offeringCreditsMilli !== null && $section->publicationVersionId === null) {
                throw new DomainException('Publish and select the section offering credits before registration.');
            }
            if (
                $course->publicationVersionId === null && $course->creditPolicy !== null
                && $course->creditPolicy->minimumMilli !== $course->creditPolicy->maximumMilli
            ) {
                throw new DomainException(
                    'Select a published course definition for this credit range before registration.',
                );
            }
            $window = $this->windows->checked($section->termId, $this->repository->window($section->termId));
            $courseRead ??= new RegistrationCourseRead();
            if (
                !$section->active ||
                !$course->active ||
                $this->repository->sectionCount($sectionId) >=
                    $section->capacity
            ) {
                throw new DomainException("The section is inactive or full.");
            }
            $selection = $this->pursuits->activeSelection($studentId, $assignment);
            $assignment = $selection->id;
            $this->requireProgram($section, $selection->programId);
            $required =
                $course->publishedPrerequisites ??
                $this->repository->requiredCourses($course->id);
            $coursesBefore = $this->repository->enrolledCount(
                $studentId,
                $section->termId,
            );
            $courseRead->selection($sectionId, $course, $required);
            $budget ??= new \Domain\Shared\EventSourcing\HistoryReadBudget();
            $graduate = $this->graduate->assess($selection, $section, $course, $budget, $courseRead);
            $graduateEvidence = $this->graduate->requireMet($graduate);
            $campusEvidence = ProgramCampusEligibility::requireMet(
                $this->campusAssessment($selection, $section, $budget)
            );
            $prerequisites = $this->prerequisites->evaluate(
                $studentId,
                $assignment,
                $required,
                $budget,
                $course->prerequisiteGroups,
                $section->id,
                $section->termId,
                $course->publicationVersionId,
                $course->prerequisiteGroups?->hasRecency() ? $this->offeringDefinition($section->id) : null,
            );
            $corequisites = $this->corequisites->evaluate(
                $studentId,
                $assignment,
                $section->termId,
                $course->prerequisiteGroups?->corequisites ?? [],
                $budget,
                $corequisitePlan,
            );
            $this->corequisites->requireMet($corequisites);
            $prerequisitesMet = $prerequisites->isMet();
            $schedule = $this->schedules->evaluate(
                $studentId,
                $sectionId,
                $scheduleRead,
            );
            if ($schedule->blocked()) {
                throw new \Domain\Shared\Exception\ValidationException([
                    [
                        "field" => "schedule",
                        "message" =>
                            "Published section meetings overlap this student's timetable. " .
                            "Choose another section or ask the registrar to review it.",
                    ],
                ]);
            }
            // History verification may take time. Evaluate dates against the clock after those reads.
            $now = $this->clock->now();
            $this->assertEligible(
                $student,
                $section,
                $window,
                $byStaff,
                $coursesBefore,
                $prerequisitesMet,
                $now,
            );
            $enrollment = new Enrollment(
                $corequisitePlan === null ? EntityId::generate() :
                    new EntityId($corequisitePlan->sections[$sectionId->value]["enrollment_id"] ??
                        throw new \LogicException("A committed registration plan is required.")),
                $studentId,
                $sectionId,
                $section->termId,
                $byStaff
                    ? RegistrationStatus::StaffRegistered
                    : RegistrationStatus::SelfRegistered,
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
                    "required_course_ids" => $prerequisites->required,
                    "opens_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                        $window->dates->start,
                    ),
                    "closes_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                        $window->dates->end ??
                            throw new \LogicException(
                                "A registration window must end.",
                            ),
                    ),
                ],
                $actor,
                $prerequisites,
                $schedule,
                $budget,
                $courseRead,
                $corequisites,
                $window,
                $graduateEvidence,
                $campusEvidence,
            );
            $this->credits->open(
                $enrollment,
                $course,
                $actor,
                $assignment,
                $section->publicationVersionId,
            );
            $this->billing->charge(
                $studentId,
                $section->termId,
                $enrollment->id,
                $section->fee,
                $course->registrationCreditsMilli(),
                new EventContext(
                    $actor,
                    $now,
                    $enrollment->id,
                    $enrollment->id,
                    reason: "Account charges caused by accepted course registration.",
                ),
                $chargeQuote,
            );
            $this->audit->record(
                $actor,
                "registration.created",
                $enrollment->id,
            );
            $this->waitlists->complete($waitlistClaim, $enrollment, $actor);
            $this->verifyCourseSelection($sectionId, $budget, $courseRead);
            if ($prerequisites->permission !== null || $course->prerequisiteGroups?->hasRecency()) {
                $freshPrerequisites = $this->prerequisites->evaluate(
                    $studentId,
                    $assignment,
                    $required,
                    $budget,
                    $course->prerequisiteGroups,
                    $section->id,
                    $section->termId,
                    $course->publicationVersionId,
                    $course->prerequisiteGroups?->hasRecency() ? $this->offeringDefinition($section->id) : null,
                );
                if ($freshPrerequisites->revision() !== $prerequisites->revision()) {
                    throw new ConcurrentModificationException(
                        \Codefy\Framework\Helpers\trans(
                            'The prerequisite decision changed. Review registration again.',
                        ),
                    );
                }
            }
            if ($corequisites !== null) {
                $freshCorequisites = $this->corequisites->evaluate(
                    $studentId,
                    $assignment,
                    $section->termId,
                    $course->prerequisiteGroups?->corequisites ?? [],
                    $budget,
                    $corequisitePlan,
                );
                $this->corequisites->requireMet($freshCorequisites);
                if ($freshCorequisites->revision() !== $corequisites->revision()) {
                    throw new ConcurrentModificationException(
                        "Co-requisite support changed. Review registration again.",
                    );
                }
            }
            $freshSection = $this->repository->section($sectionId);
            if (
                $freshSection->programPolicy->programIds !== $section->programPolicy->programIds
                || $freshSection->programPolicy->requireCampus !== $section->programPolicy->requireCampus
            ) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans('The section program policy changed during registration.')
                );
            }
            if ($section->programPolicy->programIds !== [] || $section->programPolicy->requireCampus) {
                $freshPursuit = $this->pursuits->activeSelection($studentId, $assignment);
                if ($freshPursuit->programId->value !== $selection->programId->value) {
                    throw new ConcurrentModificationException(
                        \Codefy\Framework\Helpers\trans('The selected program pursuit changed during registration.')
                    );
                }
                $this->requireProgram($freshSection, $freshPursuit->programId);
                if (
                    ProgramCampusEligibility::requireMet($this->campusAssessment(
                        $freshPursuit,
                        $freshSection,
                        $budget
                    )) !== $campusEvidence
                ) {
                    throw new ConcurrentModificationException(
                        \Codefy\Framework\Helpers\trans('Program campus evidence changed. Review registration again.')
                    );
                }
            }
            $freshGraduate = $this->graduate->assess(
                $this->pursuits->activeSelection($studentId, $assignment),
                $this->repository->section($sectionId),
                $this->repository->courseForSection($this->repository->section($sectionId)),
                $budget,
                $courseRead,
            );
            $this->graduate->requireMet($freshGraduate);
            if (GraduateRegistrationGate::revision($freshGraduate) !== GraduateRegistrationGate::revision($graduate)) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        'Graduate-course eligibility changed during registration. Review again.',
                    ),
                );
            }
            $freshWindow = $this->windows->checked($section->termId, $this->repository->window($section->termId));
            if ($freshWindow->fingerprint() !== $window->fingerprint()) {
                throw new ConcurrentModificationException('The assessed registration window changed. Review again.');
            }
            // Final checks are part of the same unit of work, including the seat, credit, charge and audit.
            $this->assertEligible(
                $this->admissions->student($studentId),
                $section,
                $window,
                $byStaff,
                $coursesBefore,
                $prerequisitesMet,
                $this->clock->now(),
            );
            $freshSchedule = $this->schedules->evaluate($studentId, $sectionId);
            if (
                $freshSchedule->revision() !== $schedule->revision() ||
                $freshSchedule->blocked()
            ) {
                throw new ConcurrentModificationException(
                    "The reviewed timetable changed during registration. Review it again.",
                );
            }
            $this->verifyAccess(
                $actor,
                $student,
                $person->ownerUserId,
                $byStaff,
            );
            return $enrollment;
        });
    }

    /** Authorized, selected-student preview. The registration command repeats the assessment under its own locks.
     * @return array<string,mixed> */
    public function reviewSchedule(
        EntityId $studentId,
        EntityId $sectionId,
    ): array {
        $actor = $this->access->authenticatedUserId();
        $initial = $this->admissions->student($studentId);
        $person = $this->admissions->person($initial->personId);
        $this->authorize($actor, $person->ownerUserId, false);
        return $this->transactions->run(function () use (
            $studentId,
            $sectionId,
            $actor,
            $initial,
        ): array {
            $this->repository->lockStudent($studentId);
            $this->repository->lockPerson($initial->personId);
            $this->repository->lockSection($sectionId);
            $current = $this->admissions->student($studentId);
            if ($current->personId->value !== $initial->personId->value) {
                throw new ConcurrentModificationException(
                    "The student identity changed. Review again.",
                );
            }
            $person = $this->admissions->person($current->personId);
            $this->authorize($actor, $person->ownerUserId, false);
            $this->repository->section($sectionId);
            $existing = $this->repository->enrollment($studentId, $sectionId);
            if ($existing !== null && $existing->status->isEnrolled()) {
                $this->enrollments->verifyExisting(
                    $existing,
                    $this->repository->section($sectionId),
                );
                $result = [
                    "status" => "not_rechecked",
                    "blocked" => false,
                    "conflicts" => [],
                    "conflict_count" => null,
                    "unpublished_count" => null,
                    "mode" => null,
                ];
            } else {
                $decision = $this->schedules->evaluate($studentId, $sectionId);
                $result = $decision->summary() + [
                    "conflicts" => $decision->facts["conflicts"],
                    "revision" => $decision->revision(),
                ];
            }
            $this->audit->record(
                $actor,
                "registration.schedule_reviewed",
                $studentId,
            );
            $this->verifyAccess($actor, $current, $person->ownerUserId, false);
            return $result;
        });
    }

    /** Internal coordinator read: callers own current student/section authorization. */
    public function offeringDefinition(EntityId $section): ?\Domain\Registration\ValueObject\PublishedSectionDefinition
    {
        return $this->enrollments->selectedOffering($section);
    }

    /** Shared private assessment used by individual, group, schedule and reinstatement coordinators. */
    public function graduateAssessment(
        \Domain\Student\StudentProgram $pursuit,
        \Domain\Registration\Section $section,
        \Domain\Catalog\Course $course,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget
    ): array {
        return $this->graduate->assess($pursuit, $section, $course, $budget);
    }

    public function campusAssessment(
        \Domain\Student\StudentProgram $pursuit,
        Section $section,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
    ): array {
        return $this->enrollments->assessCampus($pursuit, $section, $budget);
    }

    private function requireProgram(\Domain\Registration\Section $section, EntityId $program): void
    {
        $check = $section->programPolicy->check($program);
        if ($section->programPolicy->programIds !== [] && $section->publicationVersionId === null) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'program_eligibility',
                    'message' => \Codefy\Framework\Helpers\trans(
                        'Publish and select the eligible program policy before registration.',
                    )],
            ]);
        }
        if ($check['status'] === 'blocked') {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'program_eligibility',
                'message' => $check['message']]]);
        }
    }

    public function verifyCourseSelection(
        EntityId $sectionId,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        RegistrationCourseRead $read,
    ): void {
        $section = $this->repository->section($sectionId);
        $course = $this->repository->courseForSection($section);
        $required = $course->publishedPrerequisites ?? $this->repository->requiredCourses($course->id);
        $this->enrollments->verifyOffering($section, $course);
        $read->selection($sectionId, $course, $required);
        $this->enrollments->verifyCourse($course, $budget, $read);
    }

    /** Internal completion check for a coordinated registration transaction; never creates or repairs facts. */
    public function verifyCompletion(
        EntityId $studentId,
        EntityId $sectionId,
        EntityId $actor,
        bool $byStaff,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        ?string $scheduleRevision = null,
        ?string $prerequisiteRevision = null,
        ?RegistrationCourseRead $courseRead = null,
        ?CoRequisitePlan $corequisiteRead = null,
    ): void {
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        $this->authorize($actor, $person->ownerUserId, $byStaff);
        $this->activity->verify($student);
        $this->primaryPrograms->verify($student);
        $section = $this->repository->section($sectionId);
        $course = $this->repository->courseForSection($section);
        $enrollment = $this->repository->enrollment($studentId, $sectionId);
        if (
            $enrollment === null ||
            !$enrollment->status->isEnrolled() ||
            !$section->active ||
            !$course->active ||
            $this->repository->sectionCount($sectionId) > $section->capacity
        ) {
            throw new ConcurrentModificationException(
                "The accepted schedule changed before completion. Review it again.",
            );
        }
        $this->verifyCourseSelection($sectionId, $budget, $courseRead ?? new RegistrationCourseRead());
        $accepted = $this->enrollments->verifyExisting($enrollment, $section, $budget);
        $this->credits->verifyOpenEnrollment($enrollment, $budget);
        $campusProof = $accepted->campusEvidence();
        $selection = $this->pursuits->activeSelection($studentId, $campusProof === null
            ? null : new EntityId($campusProof->data['pursuit_id']));
        $assignment = $selection->id;
        $this->requireProgram($section, $selection->programId);
        if (
            ProgramCampusEligibility::requireMet($this->campusAssessment($selection, $section, $budget))
            !== $campusProof?->data
        ) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans('Program campus evidence changed. Review registration again.')
            );
        }
        $this->graduate->requireMet($this->graduate->assess($selection, $section, $course, $budget));
        $required = $course->publishedPrerequisites ?? $this->repository->requiredCourses($course->id);
        $prerequisites = $this->prerequisites->evaluate(
            $studentId,
            $assignment,
            $required,
            $budget,
            $course->prerequisiteGroups,
            $section->id,
            $section->termId,
            $course->publicationVersionId,
            $course->prerequisiteGroups?->hasRecency() ? $this->offeringDefinition($section->id) : null,
        );
        if (($course->prerequisiteGroups?->corequisites ?? []) !== []) {
            $this->corequisites->verifyAccepted($enrollment, $budget, $corequisiteRead);
        }
        $this->assertEligible(
            $student,
            $section,
            $this->windows->checked($section->termId, $this->repository->window($section->termId)),
            $byStaff,
            max(0, $this->repository->enrolledCount($studentId, $section->termId) - 1),
            $prerequisites->isMet(),
            $this->clock->now(),
        );
        $schedule = $this->schedules->evaluate($studentId, $sectionId);
        if (
            $schedule->blocked() ||
            ($scheduleRevision !== null && $schedule->revision() !== $scheduleRevision) ||
            ($prerequisiteRevision !== null && $prerequisites->revision() !== $prerequisiteRevision)
        ) {
            throw new ConcurrentModificationException(
                "The reviewed timetable or prerequisite support changed before completion. Review again.",
            );
        }
        $this->verifyAccess($actor, $student, $person->ownerUserId, $byStaff);
    }

    /** Internal original-attempt verification. The caller authorizes and locks the student.
     * @return array<string,mixed> */
    public function verifyChangeSource(
        EntityId $student,
        EntityId $enrollment,
        EntityId $credit,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
        bool $removed = false,
    ): array {
        $enrolled = $this->enrollments->evaluationFacts($enrollment, $student, $budget);
        $academic = $this->credits->evaluationFacts($credit, $student, $budget);
        $facts = $enrolled["facts"];
        $work = $academic["credit"];
        if (
            $work["source_id"] !== $enrollment->value ||
            $work["status"] !== $facts["status"] ||
            ($removed && $facts["status"] !== "D")
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return ["enrollment" => $enrolled, "academic" => $academic];
    }

    private function authorize(
        EntityId $actor,
        ?EntityId $owner,
        bool $byStaff,
    ): void {
        if (
            $this->access->requireOwnerOrPermission(
                $owner,
                "sis:registration:manage",
            )->value !== $actor->value ||
            ($byStaff &&
                $this->access->requirePermission("sis:registration:manage")
                    ->value !== $actor->value)
        ) {
            throw new AccessDeniedException("The registration actor changed.");
        }
    }

    private function verifyAccess(
        EntityId $actor,
        Student $student,
        ?EntityId $owner,
        bool $byStaff,
    ): void {
        $current = $this->admissions->student($student->id);
        if ($current->personId->value !== $student->personId->value) {
            throw new ConcurrentModificationException(
                "The student identity changed. Reload before registering.",
            );
        }
        $currentOwner = $this->admissions->person($current->personId)
            ->ownerUserId;
        if ($currentOwner?->value !== $owner?->value) {
            throw new ConcurrentModificationException(
                "The student account link changed. Reload before registering.",
            );
        }
        $this->authorize($actor, $currentOwner, $byStaff);
    }

    private function assertEligible(
        Student $student,
        Section $section,
        RegistrationWindow $window,
        bool $byStaff,
        int $coursesBefore,
        bool $prerequisitesMet,
        \DateTimeImmutable $on,
    ): void {
        $blocking = $this->repository->blockingRestriction(
            $student->personId,
            $on,
            $byStaff,
        );
        new RegistrationPolicy()->assertEligible(
            $student,
            $blocking === null ? [] : [$blocking],
            $on,
            $byStaff,
            $window->isOpen($on),
            $section->webRegistration,
            $coursesBefore,
            $window->courseLimit,
            $prerequisitesMet,
        );
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
