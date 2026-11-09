<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\PrimaryProgramWasObserved;
use Domain\Student\Event\PrimaryProgramWasSelected;
use Domain\Student\ValueObject\PrimaryProgramSchema;

/** Current selection reconstructed in recording order; this slice takes effect on confirmation. */
final class StudentPrimaryProgram extends EventSourcedAggregate
{
    private ?string $person = null;
    private ?string $entryTerm = null;
    private ?string $decision = null;
    /** @var array<string,mixed>|null */
    private ?array $selection = null;
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        PrimaryProgramSchema::validate($event::class, $event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenPrimaryProgramWasObserved(PrimaryProgramWasObserved $event): void
    {
        $p = $event->payload();
        if ($this->selection !== null || $p["student_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->person = $p["person_id"];
        $this->entryTerm = $p["institutional_entry_term_id"];
        $this->selection = PrimaryProgramSchema::normalizedSelection($p["selection"]);
        $this->decision = $p["decision_id"];
    }
    public function whenPrimaryProgramWasSelected(PrimaryProgramWasSelected $event): void
    {
        $p = $event->payload();
        if (
            $this->selection === null ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["selection"]["assignment_id"] === $this->selection["assignment_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $this->selection = PrimaryProgramSchema::normalizedSelection($p["selection"]);
        $this->decision = $p["decision_id"];
    }
    /** @return array<string,mixed>|null */
    public function selection(): ?array
    {
        return $this->selection;
    }
    public function person(): ?string
    {
        return $this->person;
    }
    public function entryTerm(): ?string
    {
        return $this->entryTerm;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
}
