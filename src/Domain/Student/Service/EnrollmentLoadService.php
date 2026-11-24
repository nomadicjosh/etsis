<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\Enrollment;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\EnrollmentLoadRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\ValueObject\EnrollmentLoadBands;
use Domain\Student\ValueObject\EnrollmentLoadPolicySelection;

/** Working current-term review, never an official dated/census determination or eligibility decision. */
final readonly class EnrollmentLoadService
{
    public function __construct(
        private EnrollmentLoadRepository $records,
        private EnrollmentLoadPolicyService $policies,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private RegistrationRepository $registration,
        private EnrollmentLedger $enrollments,
        private InstitutionalCreditLedger $credits,
        private StudentProgramStatusLedger $programs,
        private StudentPrimaryProgramLedger $primary,
        private AdvisorProgressAccess $advisors,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function terms(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        $this->scope($student, $pursuit);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([
                ["field" => "search", "message" => "Search by term code, using up to eighty characters."],
            ]);
        }
        $result = $this->records->terms(trim($search), $after);
        $this->authorize($student, $pursuit, $channel, $actor);
        return $result;
    }

    public function review(EntityId $student, EntityId $pursuit, EntityId $term, string $channel): array
    {
        return $this->collect($student, $pursuit, $term, $channel);
    }

    /** Internal retained decision context; never returned through owner/advisor working reviews. */
    public function determinationContext(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        HistoryReadBudget $budget,
        ?EnrollmentLoadPolicySelection $selection = null,
    ): array {
        $this->access->requirePermission('sis:load:determine');
        return $this->collect($student, $pursuit, $term, 'staff', true, $budget, $selection);
    }

    public function policyChoices(
        EntityId $student,
        EntityId $pursuit,
        string $source,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, 'staff');
        $this->access->requirePermission(LoadDeterminationService::PERMISSION);
        $this->access->requirePermission(EnrollmentLoadPolicyService::PERMISSION);
        return $this->transactions->run(function () use ($student, $pursuit, $source, $cursor, $actor): array {
            $this->registration->lockStudent($student);
            $budget = new HistoryReadBudget();
            [, $assignment] = $this->scope($student, $pursuit, $budget);
            $result = $this->policies->choicesForDetermination($assignment->programId, $source, $cursor, $budget);
            $this->scope($student, $pursuit, $budget);
            $this->authorize($student, $pursuit, 'staff', $actor);
            $this->access->requirePermission(LoadDeterminationService::PERMISSION);
            $this->access->requirePermission(EnrollmentLoadPolicyService::PERMISSION);
            return $result;
        });
    }

    private function collect(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        string $channel,
        bool $retain = false,
        ?HistoryReadBudget $shared = null,
        ?EnrollmentLoadPolicySelection $selection = null,
    ): array {

        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $term,
            $channel,
            $actor,
            $retain,
            $shared,
            $selection,
        ): array {
            $this->registration->lockStudent($student);
            $this->authorize($student, $pursuit, $channel, $actor);
            [$s, $p] = $this->scope($student, $pursuit, $shared);
            $period = $this->records->term($term);
            $timing =
                $period["ends_on"] < $this->clock->now()->format("Y-m-d")
                    ? "past"
                    : ($period["starts_on"] > $this->clock->now()->format("Y-m-d")
                        ? "future"
                        : "current");
            $base = [
                "term" => $period,
                "term_timing" => $timing,
                "program" => $this->students->program($p->programId),
                "reviewed_at" => $this->clock->now()->format(DATE_ATOM),
                "basis" => "active_registered_credits",
                "official" => false,
            ];
            try {
                $budget = $shared ?? new HistoryReadBudget();
                $policy = $retain
                    ? $this->policies->resolveForDetermination($p->programId, $selection, $budget)
                    : $this->policies->resolve($p->programId);
                $inventory = $this->records->inventory($student, $term);
                if (count($inventory) > 200) {
                    throw new HistoryReadLimitException();
                }
                $sources = [];
                $total = 0;
                $unknown = false;
                $items = [];
                foreach ($inventory as $candidate) {
                    $id = new EntityId($candidate["id"]);
                    $input = $this->enrollments->evaluationFacts($id, $student, $budget);
                    $facts = $input["facts"];
                    if ($facts["term_id"] !== $term->value) {
                        throw new CorruptHistoryException();
                    }
                    if (!in_array($facts["status"], ["A", "N"], true)) {
                        continue;
                    }
                    $enrollment = new Enrollment(
                        $id,
                        $student,
                        new EntityId($facts["section_id"]),
                        $term,
                        RegistrationStatus::from($facts["status"]),
                        new \DateTimeImmutable($facts["registered_at"]),
                        new \DateTimeImmutable($facts["status_at"]),
                        $facts["version"],
                    );
                    $credit = $this->credits->registrationLoadFacts($enrollment, $budget);
                    $units = $credit["credits_milli"];
                    if (!$input["history_recorded"] || $input["earlier_history_unknown"] || $units === null) {
                        $unknown = true;
                        $units = null;
                    } elseif (!is_int($units) || $units < 0 || $total > PHP_INT_MAX - $units) {
                        throw new CorruptHistoryException();
                    } else {
                        $total += $units;
                    }
                    if ($retain && $units !== null) {
                        $sources[] = ['enrollment_id' => $id->value, 'enrollment_version' => $input['history_version'],
                            'credit_id' => $credit['credit_id'], 'credit_version' => $credit['history_version'],
                            'credits_milli' => $units, 'course_code' => $credit['course_code'],
                            'course_title' => $credit['course_title']];
                    }
                    $items[] = [
                        "course_code" => $credit["course_code"],
                        "course_title" => $credit["course_title"],
                        "credits_milli" => $units,
                        "registered_at" => $facts["registered_at"],
                        ...$channel === 'staff'
                            ? ['enrollment_id' => $id->value, 'section_id' => $facts['section_id']] : [],
                    ];
                }
                $result = $base + [
                    "status" => $unknown ? "needs_review" : ($items === [] ? "not_registered" : "ready"),
                    "enrollment_count" => count($items),
                    "credits_milli" => $unknown ? null : $total,
                    "classification" =>
                        $unknown || $items === [] || $policy["definition"] === null
                            ? null
                            : new EnrollmentLoadBands($policy["definition"])->classify($total),
                    "policy" => $policy,
                    "items" => $items,
                ] + ($retain ? ["sources" => $sources] : []);
            } catch (HistoryReadLimitException) {
                $result = $base + [
                    "status" => "limited",
                    "credits_milli" => null,
                    "classification" => null,
                    "items" => [],
                ];
            } catch (CorruptHistoryException | RecordNotFoundException) {
                $result = $base + [
                    "status" => "needs_review",
                    "credits_milli" => null,
                    "classification" => null,
                    "items" => [],
                ];
            }
            $this->audit->record($actor, "student.enrollment_load_reviewed", $student);
            $this->authorize($student, $pursuit, $channel, $actor);
            $this->scope($student, $pursuit, $shared);
            if ($retain) {
                $this->access->requirePermission("sis:load:determine");
            }
            if ($selection !== null) {
                $this->access->requirePermission(EnrollmentLoadPolicyService::PERMISSION);
            }
            return $result;
        });
    }

    private function scope(EntityId $student, EntityId $pursuit, ?HistoryReadBudget $budget = null): array
    {
        $s = $this->admissions->student($student);
        $p = $this->students->assignment($pursuit);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        $budget ??= new HistoryReadBudget();
        $this->programs->verify($p, $budget);
        $primary = $this->primary->documentContext($s, $budget);
        if ($p->status !== "active" || $primary["assignment_id"] !== $pursuit->value) {
            throw new ValidationException([
                [
                    "field" => "pursuit",
                    "message" =>
                        "Review current enrollment load under the current primary, active program pursuit.",
                ],
            ]);
        }
        return [$s, $p];
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = match ($channel) {
            "staff" => $this->access->requirePermission("sis:student:read"),
            "advisor" => $this->advisors->requireAdvisor($student, $pursuit),
            "owner" => $this->owner($student),
            default => throw new AccessDeniedException(),
        };
        if (
            $actor->value !== $this->access->authenticatedUserId()->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function owner(EntityId $student): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        if ($person->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
}
