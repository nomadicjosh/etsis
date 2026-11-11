<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;

/** Match a requested page under one student lock, without allocating or retaining an audit result. */
final readonly class AcademicWorkMatchService
{
    public function __construct(
        private AcademicWorkService $work,
        private RequirementAdjustmentService $adjustments,
        private CourseMatchEvaluator $evaluator,
        private StudentRequirementsService $requirements,
        private UnitOfWork $transactions,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(EntityId $student, EntityId $assignment, ?EntityId $after = null): array
    {
        $this->requirements->authorizeRead($student, $assignment);
        return $this->transactions->run(function () use ($student, $assignment, $after): array {
            // The nested page read locks the student; joined units of work hold that lock through this result.
            $page = $this->work->page($student, $assignment, $after);
            $preview = $this->adjustments->preview($student, $assignment);
            $requirements = $preview["requirements"];
            $context = null;
            if ($requirements !== null) {
                $page["items"] = $this->evaluator->page(
                    $page["items"],
                    $requirements,
                    $preview["effective_minimums"],
                );
                $context = [
                    "requirements_version_id" => $requirements["version_id"],
                    "version" => $requirements["version"],
                    "name" => $requirements["definition"]["name"],
                    "academic_year_code" => $requirements["context"]["academic_year_code"],
                    "group_reuse" => $requirements["definition"]["policies"]["group_reuse"] ?? null,
                    "cohort_changed" => $preview["cohort_changed"],
                    "approval_conflicts" =>
                        ($preview["effective_minimums"]["substitution_conflicts"] ?? []) !== [],
                ];
            }
            $this->requirements->authorizeRead($student, $assignment);
            return $page + [
                "matches_context" => $context,
                "match_limit" => CourseMatchEvaluator::MATCH_LIMIT,
            ];
        });
    }
}
