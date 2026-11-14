<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;

/** Read-only current-work calculation using the pursuit's retained policies. Not a complete degree audit. */
final readonly class AcademicCalculationService
{
    public function __construct(
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private AcademicPolicyEvaluator $evaluator,
        private RetainedAttemptReader $attempts,
        private StudentRecordRepository $students,
        private UnitOfWork $transactions,
        private GradingRepublicationVerifier $grading,
    ) {
    }

    /** Internal budget injection cannot be supplied by an HTTP caller.
     * @return array<string,mixed>
     */
    public function preview(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        $this->requirements->authorizeRead($student, $assignment);
        try {
            return $this->transactions->run(function () use ($student, $assignment, $budget): array {
                $this->students->lock($student);
                $budget ??= new HistoryReadBudget();
                $context = $this->requirements->calculationContext($student, $assignment, $budget);
                if ($context === null) {
                    $this->requirements->authorizeRead($student, $assignment);
                    return ["status" => "needs_selection"];
                }
                $inputs = $this->inputs->collect($student, $assignment, $budget);
                if ($inputs->status === AcademicInputStatus::Limited) {
                    throw new HistoryReadLimitException();
                }
                if ($inputs->status === AcademicInputStatus::Inconsistent) {
                    throw new CorruptHistoryException();
                }
                $requirements = $context["requirements"];
                $requirements = $this->grading->prepare($inputs->items, $requirements, $budget);
                $policy = AuditCalculationPolicy::fromRequirements($requirements);
                $attempts = $this->attempts->forPolicy($inputs, $policy, $budget);
                $result = $this->evaluator->evaluate($attempts, $policy);
                $ordering = [];
                foreach ($attempts as $attempt) {
                    $ordering[$attempt->id->value] = match ($policy->attemptOrder) {
                        RepeatAttemptOrder::AcademicPeriod => $attempt->academicOn,
                        RepeatAttemptOrder::CompletionDate => $attempt->completedOn,
                        null => null,
                    };
                }
                $byId = array_column($inputs->items, null, "id");
                $records = [];
                foreach ($result->dispositions as $disposition) {
                    $work = $byId[$disposition["id"]];
                    $records[] = $disposition + [
                        "course_code" => $work["course_code"],
                        "course_title" => $work["course_title"],
                        "credit_type" => $work["credit_type"],
                        "grading_republication_verified" =>
                    isset($policy->identicalGradeScaleVersions[$work['grade']['scale_version_id'] ?? '']),
                        "ordering_on" => $ordering[$disposition["id"]],
                    ];
                }
                $issues = [];
                foreach ($result->issues as $issue) {
                    $issues[$issue["component"] . ":" . $issue["code"]] = [
                        "component" => $issue["component"],
                        "code" => $issue["code"],
                    ];
                }
                $this->requirements->authorizeRead($student, $assignment);
                return [
                    "status" => "review",
                    "input_status" => $inputs->status->value,
                    "current_record_count" => count($inputs->items),
                    "context" => [
                        "requirements_version_id" => $requirements["version_id"],
                        "version" => $requirements["version"],
                        "name" => $requirements["definition"]["name"],
                        "academic_year_code" => $requirements["context"]["academic_year_code"],
                        "cohort_changed" => $context["cohort_changed"],
                    ],
                    "policy" => [
                        "repeat_credit" => $policy->credit?->value,
                        "repeat_gpa" => $policy->gpa?->value,
                        "transfer_overall_gpa" => $policy->transferGpa?->value,
                        "attempt_order" => $policy->attemptOrder?->value,
                    ],
                    "totals" => [
                        "earned_milli" => $result->earnedMilli,
                        "institutional_minimum_milli" => $result->residentMinimumMilli,
                        "institutional_maximum_milli" => $result->residentMaximumMilli,
                        "credit_ready" => $result->earnedMilli !== null,
                        "gpa_ready" => $result->gpa !== null,
                        "gpa_milli" => $result->gpa?->milli(),
                        "gpa_credits_milli" => $result->gpa?->creditsMilli,
                        "quality_points_milli" => $result->gpa?->qualityMilli,
                    ],
                    "issues" => array_values($issues),
                    "records" => $records,
                ];
            });
        } catch (HistoryReadLimitException) {
            $status = "limited";
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $status = "inconsistent";
        }
        // No partial calculation survives a failed nested read/transaction.
        $this->requirements->authorizeRead($student, $assignment);
        return ["status" => $status];
    }
}
