<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\StudentSpecializationsWereSelected;
use Domain\Student\ValueObject\StudentSpecializationSchema;

/** One pursuit's selection chain, independent of degree requirements and earned academic work. */
final class StudentSpecializations extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $selection = null;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !$event instanceof StudentSpecializationsWereSelected ||
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
            $p['previous_decision_id'] !== ($this->selection['decision_id'] ?? null)
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
        $this->selection = $p;
    }
    /** @return array<string,mixed>|null */
    public function selection(): ?array
    {
        return $this->selection;
    }
}
