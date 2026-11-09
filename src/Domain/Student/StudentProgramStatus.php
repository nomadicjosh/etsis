<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\StudentProgramWasObserved;
use Domain\Student\Event\StudentProgramStatusWasChanged;
use Domain\Student\ValueObject\StudentProgramStatusSchema;

/** Current program-assignment status. Decisions in this slice take effect on confirmation. */
final class StudentProgramStatus extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $facts = null;
    private ?string $decision = null;
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        StudentProgramStatusSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenStudentProgramWasObserved(StudentProgramWasObserved $event): void
    {
        $p = $event->payload();
        if ($this->facts !== null || $p["assignment"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->facts = $p["assignment"];
        $this->decision = $p["decision_id"];
    }
    public function whenStudentProgramStatusWasChanged(StudentProgramStatusWasChanged $event): void
    {
        $p = $event->payload();
        if (
            $this->facts === null ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["status"] === $this->facts["status"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->facts["status"] = $p["status"];
        $this->decision = $p["decision_id"];
    }
    /** @return array<string,mixed>|null */
    public function facts(): ?array
    {
        return $this->facts;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
}
