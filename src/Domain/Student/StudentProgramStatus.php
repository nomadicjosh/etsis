<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\StudentProgramWasObserved;
use Domain\Student\Event\StudentProgramStatusWasChanged;
use Domain\Student\Event\StudentProgramWasAssigned;
use Domain\Student\ValueObject\StudentProgramStatusSchema;

/** Current program-assignment status. Decisions in this slice take effect on confirmation. */
final class StudentProgramStatus extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $facts = null;
    private ?string $decision = null;
    private bool $earlierUnknown = true;
    /** @var array<string,mixed>|null */
    private ?array $source = null;
    /** @var array<string,mixed>|null */
    private ?array $cohort = null;
    private ?int $cohortVersion = null;
    private bool $cohortEarlierUnknown = true;
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
    public function whenStudentProgramWasAssigned(StudentProgramWasAssigned $event): void
    {
        $p = $event->payload();
        if ($this->facts !== null || $p["assignment"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->facts = $p["assignment"];
        $this->decision = $p["decision_id"];
        $this->earlierUnknown = false;
        $this->source = $p["source"];
    }
    public function earlierHistoryUnknown(): bool
    {
        return $this->earlierUnknown;
    }
    /** @return array<string,mixed>|null */
    public function assignmentSource(): ?array
    {
        return $this->source;
    }
    /** @return array<string,mixed>|null */
    public function cohort(): ?array
    {
        return $this->cohort;
    }
    public function cohortVersion(): ?int
    {
        return $this->cohortVersion;
    }
    public function cohortEarlierUnknown(int $bindingVersion): bool
    {
        if ($this->cohortVersion !== null) {
            return $this->cohortEarlierUnknown;
        }
        return $this->source === null ||
        $bindingVersion > 0 ||
        (($this->source["cohort"] ?? null) === null && $bindingVersion >= 0);
    }
    public function whenStudentCohortWasChanged(\Domain\Student\Event\StudentCohortWasChanged $event): void
    {
        $p = $event->payload();
        $before =
        $p["previous_catalog"] === null
        ? null
        : StudentProgramStatusSchema::catalog($p["previous_catalog"]);
        if (
            $this->facts === null ||
            $this->facts["status"] !== "active" ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["catalog"]["program_id"] !== $this->facts["program_id"] ||
            ($p["previous_catalog"] !== null &&
                $p["previous_catalog"]["program_id"] !== $this->facts["program_id"])
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->cohortVersion !== null) {
            if (
                $before !== $this->cohort ||
                $p["previous_binding_version"] !== $this->cohortVersion ||
                $p["earlier_history_unknown"] !== $this->cohortEarlierUnknown
            ) {
                throw new CorruptHistoryException();
            }
        } else {
            $initial = $this->source["cohort"] ?? null;
            $unknown = $this->cohortEarlierUnknown($p["previous_binding_version"]);
            if ($p["earlier_history_unknown"] !== $unknown) {
                throw new CorruptHistoryException();
            }
            if ($initial !== null && $p["previous_binding_version"] <= 0) {
                foreach ($initial as $field => $value) {
                    if (($p["previous_catalog"][$field] ?? null) !== $value) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            $this->cohortEarlierUnknown = $unknown;
        }
        $this->cohort = StudentProgramStatusSchema::catalog($p["catalog"]);
        $this->cohortVersion = $p["previous_binding_version"] + 1;
        $this->decision = $p["decision_id"];
    }
    public function whenStudentProgramWasReplaced(
        \Domain\Student\Event\StudentProgramWasReplaced $event,
    ): void {
        if ($event->payload()["replacement_assignment_id"] === (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->applyStatus($event->payload());
    }
    public function whenStudentProgramStatusWasChanged(StudentProgramStatusWasChanged $event): void
    {
        $this->applyStatus($event->payload());
    }
    /** @param array<string,mixed> $p */
    private function applyStatus(array $p): void
    {
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
