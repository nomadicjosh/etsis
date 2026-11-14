<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentSpecializationService;
use JsonException;

/** Resolves exact assigned curriculum once; the caller owns the student lock. */
final readonly class StudentCurriculumContext
{
    public function __construct(
        private StudentRequirementsService $degrees,
        private StudentSpecializationService $specializations,
    ) {
    }

    /** @return array<string,mixed> */
    public function resolve(
        EntityId $student,
        EntityId $assignment,
        HistoryReadBudget $budget,
        ?EntityId $specialization = null,
    ): array {
        if ($specialization === null) {
            return $this->degrees->view($student, $assignment, $budget);
        }
        $all = $this->specializations->evaluationContext(
            $student,
            $assignment,
            $budget,
            retainEarlier: true,
            only: $specialization,
        );
        return self::specialization($student, $assignment, $specialization, $all);
    }

    /**
     * @param array<string,mixed> $all
     * @return array<string,mixed>
     */
    public static function specialization(EntityId $student, EntityId $assignment, EntityId $id, array $all): array
    {
        return [
            "student_id" => $student->value,
            "assignment_id" => $assignment->value,
            "scope" => $all["scope"],
            "selection" => $all["bindings"][$id->value] ?? null,
            "requirements" => $all["requirements"][$id->value] ?? null,
            "specialization_id" => $id->value,
            "cohort_changed" => $all["cohort_changed"],
        ];
    }
    /**
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function all(EntityId $student, EntityId $assignment, HistoryReadBudget $budget): array
    {
        $degree = $this->degrees->view($student, $assignment, $budget);
        $specializations = $this->specializations->evaluationContext($student, $assignment, $budget);
        $bindings = array_map(static fn(array $p): string => $p["decision_id"], $specializations["bindings"]);
        ksort($bindings, SORT_STRING);
        $fingerprint = [
            $degree["selection"]["decision_id"] ?? null,
            $specializations["scope"]["cohort_binding_revision"],
            $specializations["scope"]["program_version_id"],
            $specializations["selection"]["decision_id"] ?? null,
            $bindings,
        ];
        return [
            "degree" => $degree,
            "specializations" => $specializations,
            "context_hash" => hash("sha256", json_encode($fingerprint, JSON_THROW_ON_ERROR)),
        ];
    }
}
