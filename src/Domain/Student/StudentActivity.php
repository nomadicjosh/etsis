<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\StudentActivityWasObserved;
use Domain\Student\Event\StudentActivityWasDecided;
use Domain\Student\ValueObject\StudentActivitySchema;

/** Constant-memory effective-date projection; recording a backdated fact does not erase later decisions. */
final class StudentActivity extends EventSourcedAggregate
{
    private string $cutoff = "9999-12-31";
    private ?string $person = null;
    private ?string $last = null;
    private ?string $selected = null;
    private ?string $effective = null;
    private ?bool $active = null;
    private bool $explicit = false;

    public function effectiveThrough(string $date): void
    {
        if ($this->playhead() !== 0 || !StudentActivitySchema::date($date)) {
            throw new \InvalidArgumentException("Set the effective cutoff before replay.");
        }
        $this->cutoff = $date;
    }
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        StudentActivitySchema::validate($event::class, $event->payload());
        if ($event->payload()["student_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenStudentActivityWasObserved(StudentActivityWasObserved $event): void
    {
        $p = $event->payload();
        if ($this->person !== null) {
            throw new CorruptHistoryException();
        }
        $this->person = $p["person_id"];
        $this->last = $p["decision_id"];
        if ($p["effective_on"] <= $this->cutoff) {
            $this->select($p);
        }
    }
    public function whenStudentActivityWasDecided(StudentActivityWasDecided $event): void
    {
        $p = $event->payload();
        if ($this->person === null || $p["previous_decision_id"] !== $this->last) {
            throw new CorruptHistoryException();
        }
        $this->last = $p["decision_id"];
        if (
            $p["effective_on"] <= $this->cutoff &&
            (!$this->explicit || $p["effective_on"] >= $this->effective)
        ) {
            $this->select($p);
            $this->explicit = true;
        }
    }
    /** @param array<string,mixed> $p */
    private function select(array $p): void
    {
        $this->selected = $p["decision_id"];
        $this->active = $p["active"];
        $this->effective = $p["effective_on"];
    }
    public function person(): ?string
    {
        return $this->person;
    }
    public function decision(): ?string
    {
        return $this->last;
    }
    public function active(): ?bool
    {
        return $this->active;
    }
    public function effectiveOn(): ?string
    {
        return $this->effective;
    }
    /** @return array{stream_version:int,last_decision_id:?string,current_decision_id:?string,effective_on:?string} */
    public function marker(): array
    {
        return [
            "stream_version" => $this->playhead(),
            "last_decision_id" => $this->last,
            "current_decision_id" => $this->selected,
            "effective_on" => $this->effective,
        ];
    }
}
