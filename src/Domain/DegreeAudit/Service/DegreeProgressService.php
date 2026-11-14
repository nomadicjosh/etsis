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
            fn(HistoryReadBudget $budget): array =>
                $this->adjustments->allocationContext($student, $assignment, $budget),
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
                $context = $this->specializations->assignedRequirements(
                    $student,
                    $assignment,
                    $specialization,
                    $budget,
                );
                $requirements = $context['requirements'];
                return $context + [
                    'effective_minimums' => $requirements === null
                        ? [] : DegreeProgressEvaluator::unadjusted($requirements),
                    'context_fields' => $requirements === null ? [] : [
                        'mode' => 'assigned_specialization',
                        'specialization' => $requirements['specialization'],
                        'program_code' => $requirements['context']['program_code'],
                        'program_title' => $requirements['context']['program_title'],
                    ],
                ];
            },
            $budget,
        ) + ['mode' => 'assigned_specialization'];
    }

    /** Staff combined review of exact assigned components; no graduation or retained-result decision.
     * @return array<string,mixed>
     */
    public function combined(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array
    {
        $actor = $this->specializations->authorizeRead($student, $assignment);
        try {
            $result = $this->transactions->run(function () use ($student, $assignment, $budget, $actor): array {
                $this->students->lock($student);
                $this->specializations->authorizeRead($student, $assignment, $actor);
                $budget ??= new HistoryReadBudget();
                $degree = $this->adjustments->allocationContext($student, $assignment, $budget);
                if ($degree['requirements'] === null) {
                    return ['status' => 'needs_selection'];
                }
                $specializations = $this->specializations->evaluationContext($student, $assignment, $budget);
                if ($degree['cohort_changed'] || $specializations['cohort_changed']) {
                    return ['status' => 'needs_review', 'issues' => ['cohort_changed']];
                }
                $r = $degree['requirements'];
                $program = $specializations['program'];
                if (
                    $program === null || $r['program_version_id'] === null ||
                    $r['program_version_id'] !== $specializations['scope']['program_version_id']
                ) {
                    return ['status' => 'needs_review', 'issues' => ['program_publication_unknown']];
                }
                $sharing = ComponentCourseSharing::tryFrom($program['specializations']['component_sharing'] ?? '');
                if ($sharing === null) {
                    return ['status' => 'needs_review', 'issues' => ['sharing_policy_unknown']];
                }
                $selected = $specializations['selection']['specialization_ids'] ?? [];
                if (
                    count($selected) < $program['specializations']['effective']['minimum'] ||
                    array_diff($selected, array_keys($specializations['requirements'])) !== []
                ) {
                    return ['status' => 'needs_review', 'issues' => ['specialization_requirements_missing']];
                }
                $components = [[
                    'kind' => 'degree', 'requirements' => $r, 'effective' => $degree['effective_minimums'],
                ]];
                foreach ($selected as $id) {
                    $requirements = $specializations['requirements'][$id];
                    if (
                        $requirements['program_version_id'] !== $r['program_version_id'] ||
                        $requirements['academic_year_id'] !== $r['academic_year_id']
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $effective = DegreeProgressEvaluator::unadjusted($requirements);
                    $effective['waived_course_ids'] = $degree['pursuit_waived_course_ids'];
                    $components[] = [
                        'kind' => 'specialization', 'requirements' => $requirements, 'effective' => $effective,
                    ];
                }
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
            $result = ['status' => 'limited'];
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $result = ['status' => 'inconsistent'];
        }
        $this->specializations->authorizeRead($student, $assignment, $actor);
        return $result + ['mode' => 'combined_curriculum'];
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
                    "context" => ($context['context_fields'] ?? []) + [
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
