<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enrollment;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\ValueObject\ReinstatementIntent;
use Domain\Registration\Waitlist\WaitlistSeatGuard;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Access\Repository\AccessRepository;
use Domain\Student\Service\StudentActivityLedger;
use Domain\Student\Service\StudentPrimaryProgramLedger;

/** Same-attempt reinstatement of a verified late withdrawal. Never posts, reverses or replaces money. */
final readonly class EnrollmentReinstatementService
{
    public const string DUTY = "sis:registration:reinstate";
    public function __construct(
        private RegistrationRepository $records,
        private AdmissionsRepository $admissions,
        private EnrollmentLedger $enrollments,
        private InstitutionalCreditLedger $credits,
        private RegistrationBatchService $assessments,
        private RegistrationService $registration,
        private WaitlistSeatGuard $waitlists,
        private StudentActivityLedger $activity,
        private StudentPrimaryProgramLedger $programs,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }
    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $actor,
            $reason,
        ): array {
            $person = $this->lock($student, $section);
            $context = $this->context($student, $section, $enrollment, $person, self::budget());
            $operation = EntityId::generate();
            $revision = $this->revision($context);
            $this->authorize($actor);
            return $this->presentation($context) + [
                "operation_id" => $operation->value,
                "enrollment_id" => $enrollment->value,
                "revision" => $revision,
                "reason" => $reason,
                "review_token" => $this->proofs->issue(
                    "enrollment-reinstatement",
                    "staff",
                    $enrollment,
                    $actor,
                    $operation,
                    ReinstatementIntent::hash(
                        $student,
                        $section,
                        $enrollment,
                        $operation,
                        $revision,
                        $reason,
                    ),
                ),
                "expires_at" => $this->clock
                    ->now()
                    ->modify("+20 minutes")
                    ->format(DATE_ATOM),
            ];
        });
    }
    public function confirm(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        $intent = ReinstatementIntent::hash(
            $student,
            $section,
            $enrollment,
            $operation,
            $revision,
            $reason,
        );
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $operation,
            $revision,
            $reason,
            $token,
            $intent,
            $actor,
        ): array {
            $person = $this->lock($student, $section);
            $budget = self::budget();
            $routing = $this->enrollments->evaluationFacts($enrollment, $student, $budget)["facts"];
            if ($routing["section_id"] !== $section->value) {
                throw new AccessDeniedException();
            }
            $accepted = $this->enrollments->reinstatementReceipt($enrollment, $operation, $budget);
            $this->proofs->validate(
                $token,
                "enrollment-reinstatement",
                "staff",
                $enrollment,
                $actor,
                $operation,
                $intent,
                $accepted !== null,
            );
            if ($accepted !== null) {
                if (
                    $accepted["actor_id"] !== $actor->value ||
                    !hash_equals($accepted["intent"], $intent)
                ) {
                    throw new AccessDeniedException();
                }
                $r = $accepted["result"];
                $this->credits->verifyReinstatementReceipt(
                    new EntityId($r["academic_credit_id"]),
                    $enrollment,
                    $operation,
                    $r["academic_version"],
                    $budget,
                );
                $this->authorize($actor);
                return $r;
            }
            $context = $this->context($student, $section, $enrollment, $person, $budget);
            if (!hash_equals($revision, $this->revision($context))) {
                throw new ConcurrentModificationException(
                    "The withdrawal, eligibility or seat facts changed. Review reinstatement again.",
                );
            }
            if (!$context["can_confirm"]) {
                throw new ValidationException([
                    [
                        "field" => "enrollment",
                        "message" =>
                            "Resolve the current registration checks before reinstating this attempt.",
                    ],
                ]);
            }
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "enrollment-reinstatement",
                "staff",
                $enrollment,
                $actor,
                $operation,
                $intent,
            );
            $claim = $this->waitlists->claim($section, $student);
            $eventContext = new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                $enrollment,
                reason: $reason,
            );
            $academic = $this->credits->reinstate(
                $context["enrollment"],
                $context["academic"],
                $operation,
                $eventContext,
                $budget,
            );
            $restored = $this->enrollments->reinstate(
                $context["enrollment"],
                $this->records->section($section),
                $academic,
                $operation,
                $revision,
                $context["assessment"]["evidence"],
                $eventContext,
                $budget,
            );
            $this->waitlists->complete($claim, $restored, $actor);
            $this->audit->record($actor, "registration.reinstated", $enrollment);
            $this->registration->verifyCompletion(
                $student,
                $section,
                $actor,
                true,
                $budget,
                scheduleRevision: $context["assessment"]["students"][0]["schedule"]["revision"],
                prerequisiteRevision: $context["assessment"]["students"][0]["prerequisites"][
                    "revision"
                ],
            );
            $this->enrollments->verifyReinstatementOffering(
                $restored,
                $this->records->section($section),
                $this->records->courseForSection($this->records->section($section)),
                $budget,
            );
            $this->credits->verifyReinstatementCompletion(
                $restored,
                $context["academic"],
                $operation,
                $budget,
            );
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "enrollment-reinstatement",
                "staff",
                $enrollment,
                $actor,
                $operation,
                $intent,
            );
            return [
                "operation_id" => $operation->value,
                "enrollment_id" => $enrollment->value,
                "status" => $restored->status->value,
                "version" => $restored->version,
                "academic_credit_id" => $academic["credit_id"],
                "academic_version" => $academic["version"],
            ];
        });
    }
    private function context(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $person,
        HistoryReadBudget $budget,
    ): array {
        $s = $this->admissions->student($student);
        if ($s->personId->value !== $person->value) {
            throw new ConcurrentModificationException("The student identity changed.");
        }
        $this->activity->verify($s);
        $this->programs->verify($s);
        $current = $this->records->enrollment($student, $section);
        if (
            $current?->id->value !== $enrollment->value ||
            $current->status->value !== "W" ||
            $current->statusAt > $this->clock->now()
        ) {
            throw new ConcurrentModificationException(
                "Choose the current, effective withdrawn attempt.",
            );
        }
        $offering = $this->records->section($section);
        $course = $this->records->courseForSection($offering);
        $basis = $this->enrollments->reinstatementBasis($current, $offering, $course, $budget);
        $academic = $this->credits->reinstatementBasis($current, $budget);
        if ($academic["restored"]["status"] !== $basis["status"]) {
            throw new CorruptHistoryException();
        }
        $assessment = $this->assessments->assessReinstatement(
            $student,
            $section,
            $enrollment,
            $budget,
        );
        if ($assessment["students"][0]["assignment_id"] !== $academic["assignment_id"]) {
            throw new ValidationException([
                [
                    "field" => "enrollment",
                    "message" =>
                        "The original academic pursuit must still be active. " .
                        "Reinstatement cannot move an attempt into another pursuit.",
                ],
            ]);
        }
        $read = new RegistrationCourseRead();
        $this->registration->verifyCourseSelection($section, $budget, $read);
        $row = $assessment["students"][0];
        $can =
        $assessment["section"]["active"] &&
        $assessment["section"]["seats_remaining"] > 0 &&
        $row["issues"] === [] &&
        !$row["schedule"]["blocked"] &&
        $row["corequisites"]["status"] !== "missing";
        return [
            "enrollment" => $current,
            "basis" => $basis,
            "academic" => $academic,
            "assessment" => $assessment,
            "can_confirm" => $can,
        ];
    }
    private function revision(array $context): string
    {
        $assessment = $context["assessment"];
        unset($assessment["evidence"]);
        return ReinstatementIntent::revision([
            $context["basis"],
            $context["academic"],
            $assessment,
            $context["can_confirm"],
        ]);
    }
    private function presentation(array $context): array
    {
        $a = $context["assessment"];
        return [
            "section" => $a["section"],
            "student" => $a["students"][0],
            "can_confirm" => $context["can_confirm"],
            "previous_status" => "W",
            "restored_status" => $context["basis"]["status"],
            "withdrawn_at" => $context["basis"]["facts"]["status_at"],
            "attempted_milli" => $context["academic"]["restored"]["attempted_milli"],
            ...isset($context['academic']['registered_credit_restoration']) ? [
                'registered_credit_restoration' => $context['academic']['registered_credit_restoration'],
            ] : [],
            "financial_effect" => "preserve_existing_postings",
        ];
    }
    private function lock(EntityId $student, EntityId $section): EntityId
    {
        $this->roles->lock();
        $this->records->lockStudent($student);
        $person = $this->admissions->student($student)->personId;
        $this->records->lockPerson($person);
        $this->records->lockSection($section);
        $this->records->lockCourse($this->records->section($section)->courseId);
        return $person;
    }
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::DUTY);
        foreach (["sis:registration:manage", "sis:gradebook:closed"] as $duty) {
            if ($this->access->requirePermission($duty)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function reason(#[\SensitiveParameter] string $reason): string
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Give a reason of up to one thousand characters.",
                ],
            ]);
        }
        return trim($reason);
    }
    private static function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(
            maximumEvents: 2000,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
    }
}
