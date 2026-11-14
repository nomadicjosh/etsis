<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Internal eligibility proof. The registration command authorizes and holds the student lock. */
final readonly class PrerequisiteService
{
    public function __construct(private AcademicInputVerifier $inputs, private WorkPursuitService $pursuits)
    {
    }

    /** @param list<string> $required */
    public function met(
        EntityId $student,
        EntityId $assignment,
        array $required,
        ?HistoryReadBudget $budget = null,
    ): bool {
        if ($required === []) {
            return true;
        }
        if (count($required) > 100 || count(array_unique($required)) !== count($required)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $budget ??= new HistoryReadBudget();
        $inputs = $this->pursuits->forPursuit($this->inputs->verify($student, $budget), $student, $assignment, $budget);
        $completed = [];
        foreach ($inputs->items as $work) {
            // Zero-credit passing courses can satisfy a prerequisite. Current rows alone cannot prove a grade.
            if (
                $work['history_recorded'] && $work['grade_recorded'] && $work['grade'] !== null &&
                $work['grade']['passes'] && in_array($work['status'], ['A', 'N'], true)
            ) {
                $completed[$work['course_id']] = true;
            }
        }
        return array_all($required, static fn(string $id): bool => isset($completed[$id]));
    }
}
