<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AcademicGpaScope;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;

/** Current general minimums and structural placement from one authorized, bounded input boundary. */
final readonly class DegreeProgressService
{
    public function __construct(
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private RequirementAdjustmentService $adjustments,
        private AcademicPolicyEvaluator $policies,
        private RetainedAttemptReader $attempts,
        private DegreeMinimumEvaluator $minimums,
        private GroupAllocationEvaluator $groups,
        private StudentRecordRepository $students,
        private UnitOfWork $transactions,
    ) {
    }

    /** Internal budget only; HTTP requests cannot supply scope, thresholds or replay allowances.
     * @return array<string,mixed>
     */
    public function preview(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        $this->requirements->authorizeRead($student, $assignment);
        try {
            return $this->transactions->run(function () use ($student, $assignment, $budget): array {
                $this->students->lock($student);
                $budget ??= new HistoryReadBudget();
                $context = $this->adjustments->allocationContext($student, $assignment, $budget);
                $requirements = $context["requirements"];
                if ($requirements === null) {
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
                $policy = AuditCalculationPolicy::fromRequirements($requirements);
                $attempts = $this->attempts->forPolicy($inputs, $policy, $budget);
                $overall = $this->policies->evaluate($attempts, $policy);
                $institutional = $this->policies->evaluate(
                    $attempts,
                    $policy,
                    AcademicGpaScope::Institutional,
                );
                $checks = $this->minimums->evaluate(
                    $overall,
                    $institutional,
                    $context["effective_minimums"]["degree"],
                );
                foreach ($checks as &$check) {
                    $check["published_milli"] = $requirements["definition"][$check["key"]];
                    $check["adjusted"] = $check["required_milli"] !== $check["published_milli"];
                }
                unset($check);
                $allocation = $this->groups->evaluate(
                    $inputs->items,
                    $requirements,
                    $context["effective_minimums"],
                );
                $byId = array_column($inputs->items, null, "id");
                $cumulative = array_column($institutional->dispositions, null, "id");
                $records = [];
                foreach ($overall->dispositions as $d) {
                    $work = $byId[$d["id"]];
                    $records[] = [
                        "id" => $d["id"],
                        "course_code" => $work["course_code"],
                        "course_title" => $work["course_title"],
                        "credit" => $d["credit"],
                        "overall_gpa" => $d["gpa"],
                        "cumulative_gpa" => $cumulative[$d["id"]]["gpa"],
                    ];
                }
                $issues = [];
                foreach (["overall" => $overall, "institutional" => $institutional] as $scope => $result) {
                    foreach ($result->issues as $issue) {
                        $component = $issue["component"] === "credit" ? "credits" : $scope;
                        $issues[$component . ":" . $issue["code"]] = [
                            "component" => $component,
                            "code" => $issue["code"],
                        ];
                    }
                }
                ksort($issues, SORT_STRING);
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
                        "group_reuse" => $requirements["definition"]["policies"]["group_reuse"] ?? null,
                    ],
                    "minimums" => $checks,
                    "allocation" => $allocation,
                    "issues" => array_values($issues),
                    "records" => $records,
                    "gpa" => [
                        "cumulative" => [
                            "ready" => $institutional->gpa !== null,
                            "milli" => $institutional->gpa?->milli(),
                            "credits_milli" => $institutional->gpa?->creditsMilli,
                            "quality_points_milli" => $institutional->gpa?->qualityMilli,
                        ],
                        "overall" => [
                            "ready" => $overall->gpa !== null,
                            "milli" => $overall->gpa?->milli(),
                            "credits_milli" => $overall->gpa?->creditsMilli,
                            "quality_points_milli" => $overall->gpa?->qualityMilli,
                        ],
                    ],
                    "policy" => [
                        "repeat_credit" => $policy->credit?->value,
                        "repeat_gpa" => $policy->gpa?->value,
                        "transfer_overall_gpa" => $policy->transferGpa?->value,
                        "attempt_order" => $policy->attemptOrder?->value,
                    ],
                ];
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = "limited";
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $status = "inconsistent";
        }
        $this->requirements->authorizeRead($student, $assignment);
        return ["status" => $status];
    }
}
