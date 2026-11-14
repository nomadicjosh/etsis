<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\StudentSpecializationsWereSelected;
use Domain\Student\Event\StudentSpecializationRequirementsWereAssigned;
use Domain\Student\ValueObject\StudentSpecializationSchema;
use Domain\Student\ValueObject\StudentSpecializationRequirementsSchema;

/** One pursuit's specialization choices and exact requirements bindings, separate from earned academic work. */
final class StudentSpecializations extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $selection = null;
    private ?string $decisionId = null;
    /** @var array<string,array<string,mixed>> */
    private array $requirements = [];

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof StudentSpecializationsWereSelected ||
                $event instanceof StudentSpecializationRequirementsWereAssigned) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenStudentSpecializationsWereSelected(StudentSpecializationsWereSelected $event): void
    {
        $p = StudentSpecializationSchema::read($event->payload());
        if (
            $p['assignment_id'] !== (string) $this->aggregateId() ||
            $p['previous_decision_id'] !== $this->decisionId
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->selection !== null) {
            foreach (['student_id', 'assignment_id', 'program_id'] as $key) {
                if ($p[$key] !== $this->selection[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            if ($p['cohort_binding_revision'] < $this->selection['cohort_binding_revision']) {
                throw new CorruptHistoryException();
            }
        }
        if (
            $this->selection === null ||
            $p['program_version_id'] !== $this->selection['program_version_id'] ||
            $p['cohort_binding_revision'] !== $this->selection['cohort_binding_revision']
        ) {
            $this->requirements = [];
        } else {
            $this->requirements = array_intersect_key(
                $this->requirements,
                array_fill_keys($p['specialization_ids'], true),
            );
        }
        $this->selection = $p;
        $this->decisionId = $p['decision_id'];
    }
    public function whenStudentSpecializationRequirementsWereAssigned(
        StudentSpecializationRequirementsWereAssigned $event,
    ): void {
        $p = StudentSpecializationRequirementsSchema::read($event->payload());
        if (
            $this->selection === null || $p['assignment_id'] !== (string) $this->aggregateId() ||
            $p['previous_decision_id'] !== $this->decisionId ||
            $p['selection_decision_id'] !== $this->selection['decision_id'] ||
            !in_array($p['specialization_id'], $this->selection['specialization_ids'], true)
        ) {
            throw new CorruptHistoryException();
        }
        $fields = ['student_id', 'assignment_id', 'program_id', 'academic_year_id',
            'program_version_id', 'cohort_binding_revision'];
        foreach ($fields as $field) {
            if ($p[$field] !== $this->selection[$field]) {
                throw new CorruptHistoryException();
            }
        }
        $this->requirements[$p['specialization_id']] = $p;
        ksort($this->requirements, SORT_STRING);
        $this->decisionId = $p['decision_id'];
    }
    public function decisionId(): ?string
    {
        return $this->decisionId;
    }
    /** @return array<string,array<string,mixed>> */
    public function requirements(): array
    {
        return $this->requirements;
    }
    /** @return array<string,mixed>|null */
    public function selection(): ?array
    {
        return $this->selection;
    }
}
