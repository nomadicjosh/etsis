<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentSpecializationService;
use InvalidArgumentException;

/** Current general minimums and structural placement from one authorized, bounded input boundary. */
final readonly class DegreeProgressService implements DegreeProgressReader
{
    public function __construct(
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private RequirementAdjustmentService $adjustments,
        private DegreeProgressEvaluator $evaluator,
        private StudentRecordRepository $students,
        private UnitOfWork $transactions,
        private StudentSpecializationService $specializations,
        private CombinedAllocationEvaluator $combined,
    ) {
    }

    /** Internal budget only; HTTP requests cannot supply scope, thresholds or replay allowances.
     * @return array<string,mixed>
     */
    public function preview(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        return $this->read(
            $student,
            $assignment,
            fn() => $this->requirements->authorizeRead($student, $assignment),
            fn(HistoryReadBudget $budget): array => $this->adjustments->allocationContext(
                $student,
                $assignment,
                $budget,
            ),
            $budget,
        );
    }

    /** Independent retained component review; no cross-component allocation or exception inference.
     * @return array<string,mixed>
     */
    public function specialization(
        EntityId $student,
        EntityId $assignment,
        EntityId $specialization,
        ?HistoryReadBudget $budget = null,
    ): array {
        $actor = $this->specializations->authorizeRead($student, $assignment);
        return $this->read(
            $student,
            $assignment,
            fn() => $this->specializations->authorizeRead($student, $assignment, $actor),
            function (HistoryReadBudget $budget) use ($student, $assignment, $specialization): array {
                $context = $this->adjustments->allocationContext($student, $assignment, $budget, $specialization);
                $requirements = $context["requirements"];
                return $context + [
                    "effective_minimums" => $context["effective_minimums"],
                    "context_fields" =>
                        $requirements === null
                            ? []
                            : [
                                "mode" => "assigned_specialization",
                                "specialization" => $requirements["specialization"],
                                "program_code" => $requirements["context"]["program_code"],
                                "program_title" => $requirements["context"]["program_title"],
                            ],
                ];
            },
            $budget,
        ) + ["mode" => "assigned_specialization"];
    }

    /** Staff combined review of exact assigned components; no graduation or retained-result decision.
     * @return array<string,mixed>
     */
    public function combined(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        return $this->curriculumProgress($student, $assignment, true, $budget);
    }

    /** @return array<string,mixed> */
    public function student(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        return $this->curriculumProgress($student, $assignment, false, $budget);
    }

    /** @return array<string,mixed> */
    private function curriculumProgress(
        EntityId $student,
        EntityId $assignment,
        bool $staff,
        ?HistoryReadBudget $budget,
    ): array {
        $actor = $staff
        ? $this->specializations->authorizeRead($student, $assignment)
        : $this->specializations->authorizeProgressRead($student, $assignment);
        $authorize = fn() => $staff
            ? $this->specializations->authorizeRead($student, $assignment, $actor)
            : $this->specializations->authorizeProgressRead($student, $assignment, $actor);
        try {
            $result = $this->transactions->run(function () use (
                $student,
                $assignment,
                $budget,
                $staff,
                $authorize,
            ): array {
                $this->students->lock($student);
                $authorize();
                $budget ??= new HistoryReadBudget();
                $all = $this->adjustments->curriculumContext($student, $assignment, $budget);
                $degree = $all["degree"];
                if ($degree["requirements"] === null) {
                    return ["status" => "needs_selection"];
                }
                $specializations = $all["specializations"];
                $selected = $specializations["selection"]["specialization_ids"] ?? [];
                if (
                    !$staff &&
                    $selected === [] &&
                    $all["routes"] === [] &&
                    ($specializations["program"]["specializations"]["effective"]["minimum"] ?? 0) === 0
                ) {
                    $inputs = $this->inputs->collect($student, $assignment, $budget);
                    if ($inputs->status === AcademicInputStatus::Limited) {
                        throw new HistoryReadLimitException();
                    }
                    if ($inputs->status === AcademicInputStatus::Inconsistent) {
                        throw new CorruptHistoryException();
                    }
                    $r = $degree["requirements"];
                    return $this->evaluator->evaluate($inputs, $r, $all["components"][0]["effective"], $budget) + [
                        "context" => [
                            "name" => $r["definition"]["name"],
                            "version" => $r["version"],
                            "academic_year_code" => $r["context"]["academic_year_code"],
                            "cohort_changed" => $degree["cohort_changed"],
                            "group_reuse" => $r["definition"]["policies"]["group_reuse"] ?? null,
                        ],
                    ];
                }
                if ($degree["cohort_changed"] || $specializations["cohort_changed"]) {
                    return ["status" => "needs_review", "issues" => ["cohort_changed"]];
                }
                $r = $degree["requirements"];
                $program = $specializations["program"];
                if (
                    $program === null ||
                    $r["program_version_id"] === null ||
                    $r["program_version_id"] !== $specializations["scope"]["program_version_id"]
                ) {
                    return ["status" => "needs_review", "issues" => ["program_publication_unknown"]];
                }
                $sharing = ComponentCourseSharing::tryFrom($program["specializations"]["component_sharing"] ?? "");
                if ($sharing === null) {
                    return ["status" => "needs_review", "issues" => ["sharing_policy_unknown"]];
                }
                $selected = $specializations["selection"]["specialization_ids"] ?? [];
                if (
                    count($selected) < $program["specializations"]["effective"]["minimum"] ||
                    array_diff($selected, array_keys($specializations["requirements"])) !== []
                ) {
                    return ["status" => "needs_review", "issues" => ["specialization_requirements_missing"]];
                }
                if ($all["routing_changed"]) {
                    return ["status" => "needs_review", "issues" => ["component_routing_changed"]];
                }
                $components = $all["components"];
                $inputs = $this->inputs->collect($student, $assignment, $budget);
                if ($inputs->status === AcademicInputStatus::Limited) {
                    throw new HistoryReadLimitException();
                }
                if ($inputs->status === AcademicInputStatus::Inconsistent) {
                    throw new CorruptHistoryException();
                }
                return $this->combined->evaluate($inputs, $components, $sharing, $budget);
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $result = ["status" => "limited"];
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $result = ["status" => "inconsistent"];
        }
        $authorize();
        return $result + ["mode" => isset($result["components"]) || $staff ? "combined_curriculum" : "degree"];
    }

    /** @param \Closure():mixed $authorize
     * @param \Closure(HistoryReadBudget):array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    private function read(
        EntityId $student,
        EntityId $assignment,
        \Closure $authorize,
        \Closure $resolve,
        ?HistoryReadBudget $budget,
    ): array {
        $authorize();
        try {
            return $this->transactions->run(function () use (
                $student,
                $assignment,
                $budget,
                $authorize,
                $resolve,
            ): array {
                $this->students->lock($student);
                $authorize();
                $budget ??= new HistoryReadBudget();
                $context = $resolve($budget);
                $requirements = $context["requirements"];
                if ($requirements === null) {
                    $authorize();
                    return ["status" => "needs_selection"];
                }
                $inputs = $this->inputs->collect($student, $assignment, $budget);
                if ($inputs->status === AcademicInputStatus::Limited) {
                    throw new HistoryReadLimitException();
                }
                if ($inputs->status === AcademicInputStatus::Inconsistent) {
                    throw new CorruptHistoryException();
                }
                $result = $this->evaluator->evaluate($inputs, $requirements, $context["effective_minimums"], $budget);
                $authorize();
                return $result + [
                    "context" => ($context["context_fields"] ?? []) + [
                        "requirements_version_id" => $requirements["version_id"],
                        "version" => $requirements["version"],
                        "name" => $requirements["definition"]["name"],
                        "academic_year_code" => $requirements["context"]["academic_year_code"],
                        "cohort_changed" => $context["cohort_changed"],
                        "group_reuse" => $requirements["definition"]["policies"]["group_reuse"] ?? null,
                    ],
                ];
            });
        } catch (HistoryReadLimitException | AllocationBudgetExhaustedException) {
            $status = "limited";
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $status = "inconsistent";
        }
        $authorize();
        return ["status" => $status];
    }
}
