<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use Domain\Registration\ValueObject\PrerequisiteDecision;

/** Internal eligibility proof. The registration command authorizes and holds the student lock. */
final readonly class PrerequisiteService
{
    public function __construct(private AcademicInputVerifier $inputs, private WorkPursuitService $pursuits)
    {
    }

    /** @param list<string> $required */
    public function evaluate(
        EntityId $student,
        EntityId $assignment,
        array $required,
        ?HistoryReadBudget $budget = null,
    ): PrerequisiteDecision {
        if ($required === []) {
            return new PrerequisiteDecision($assignment, [], []);
        }
        if (count($required) > 100 || count(array_unique($required)) !== count($required)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        sort($required, SORT_STRING);
        $budget ??= new HistoryReadBudget();
        $inputs = $this->pursuits->forPursuit($this->inputs->verify($student, $budget), $student, $assignment, $budget);
        $references = array_column($inputs->references, null, "credit_id");
        $completed = [];
        foreach ($inputs->items as $work) {
            // Zero-credit passing courses can satisfy a prerequisite. Current rows alone cannot prove a grade.
            if (
                $work['history_recorded'] && $work['grade_recorded'] && $work['grade'] !== null &&
                $work['grade']['passes'] && in_array($work['status'], ['A', 'N'], true)
            ) {
                $course = $work['course_id'];
                if (
                    !in_array($course, $required, true) ||
                    (isset($completed[$course]) && strcmp($completed[$course]['credit_id'], $work['id']) < 0)
                ) {
                    continue;
                }
                $ref = $references[$work['id']] ?? null;
                if ($ref === null) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
                $completed[$course] = ['course_id' => $course, 'credit_id' => $work['id'],
                    'origin' => $ref['origin'], 'source_id' => $ref['source_id'],
                    'academic_version' => $ref['academic_version'],
                    'registration_version' => $ref['registration_version'],
                    'pursuit_version' => $ref['pursuit_version']];
            }
        }
        ksort($completed, SORT_STRING);
        return new PrerequisiteDecision($assignment, $required, array_values($completed));
    }
}
