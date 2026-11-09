<?php

declare(strict_types=1);

namespace Domain\DegreeAudit;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\DegreeAudit\Event\StudentRequirementsWereAssigned;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class StudentRequirements extends EventSourcedAggregate
{
    /** @var array<string, mixed>|null */
    private ?array $selection = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof StudentRequirementsWereAssigned) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenStudentRequirementsWereAssigned(StudentRequirementsWereAssigned $event): void
    {
        $p = $event->payload();
        if (
            $p["previous_decision_id"] !== ($this->selection["decision_id"] ?? null) ||
            ($this->selection !== null &&
                ($p["student_id"] !== $this->selection["student_id"] ||
                    $p["program_id"] !== $this->selection["program_id"]))
        ) {
            throw new CorruptHistoryException();
        }
        $this->selection = $p;
    }
    /** @return array<string, mixed>|null */
    public function selection(): ?array
    {
        return $this->selection;
    }
}
