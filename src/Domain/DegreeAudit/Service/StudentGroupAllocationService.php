<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use InvalidArgumentException;

/** On-demand structural placement, under one authorized student/read-history boundary. */
final readonly class StudentGroupAllocationService
{
    public function __construct(
        private AcademicInputService $inputs,
        private StudentRequirementsService $requirements,
        private RequirementAdjustmentService $adjustments,
        private GroupAllocationEvaluator $evaluator,
        private StudentRecordRepository $students,
        private UnitOfWork $transactions,
    ) {
    }

    /** @return array<string,mixed> */
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
                $result = $this->evaluator->evaluate(
                    $inputs->items,
                    $requirements,
                    $context["effective_minimums"],
                );
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
                ] + $result;
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
