<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\ValueObject\EntityId;

/** Internal atomic-plan intent. Allocated identities become facts only if every registration commits. */
final class CoRequisitePlan implements \JsonSerializable
{
    public readonly array $sections;
    private ?\Domain\DegreeAudit\ValueObject\AcademicInputSet $inputs = null;
    private array $scopes = [];
    public function __construct(
        public readonly RegistrationPlanSelection $selection,
        RegistrationRepository $records,
        public readonly ?EntityId $operation = null,
    ) {
        $items = [];
        foreach ($selection->sections as $id) {
            $section = $records->section($id);
            $items[$id->value] = ['section_id' => $id->value, 'course_id' => $section->courseId->value,
                'term_id' => $section->termId->value,
                'enrollment_id' => $operation === null ? null : EntityId::generate()->value];
        }
        $this->sections = $items;
    }
    /** Only share within one assessment phase under the student lock; completion uses a fresh instance. */
    public function scopedInputs(
        EntityId $student,
        EntityId $assignment,
        \Domain\DegreeAudit\Service\AcademicInputVerifier $inputs,
        \Domain\AcademicRecord\Service\WorkPursuitService $pursuits,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
    ): \Domain\DegreeAudit\ValueObject\AcademicInputSet {
        if ($student->value !== $this->selection->student->value) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $this->inputs ??= $inputs->verify($student, $budget);
        return $this->scopes[$assignment->value] ??= $pursuits->forPursuit(
            $this->inputs,
            $student,
            $assignment,
            $budget,
        );
    }
    public function __debugInfo(): array
    {
        return ['plan' => '[protected registration assessment]'];
    }
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
    public function support(string $course, string $term): ?array
    {
        foreach ($this->sections as $item) {
            if ($item['course_id'] !== $course || $item['term_id'] !== $term) {
                continue;
            }
            return ['course_id' => $course, 'kind' => $this->operation === null ? 'planned' : 'coordinated',
                'origin' => null, 'source_id' => $item['enrollment_id'] ?? $item['section_id'], 'credit_id' => null,
                'section_id' => $item['section_id'], 'academic_version' => null, 'registration_version' => null,
                'pursuit_version' => null, 'operation_id' => $this->operation?->value];
        }
        return null;
    }
}
