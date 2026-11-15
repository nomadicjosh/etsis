<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Domain\HumanResources\Event\EmploymentProfileWasRecorded;
use Domain\HumanResources\ValueObject\EmploymentFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;

final class EmploymentHistory extends EventSourcedAggregate
{
    private ?Employee $employee = null;
    private ?string $operation = null;
    private bool $earlierUnknown = true;
    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        if (
            !$event instanceof EmploymentProfileWasRecorded || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1 || $stored->context->origin !== 'command'
            || $stored->context->actor === null || $stored->context->effectiveAt === null
            || $stored->context->correlation?->value !== ($event->payload()['operation_id'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        if ($event->payload()['provenance'] === 'changed' && $stored->context->reason === null) {
            throw new CorruptHistoryException();
        }
        $this->applyRecord($event);
    }
    public function applyRecord(AggregateChanged $event): void
    {
        if (
            !$event instanceof EmploymentProfileWasRecorded
            || !$event->aggregateId()->equals($this->aggregateId()) || $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        EmploymentFacts::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenEmploymentProfileWasRecorded(EmploymentProfileWasRecorded $event): void
    {
        $p = $event->payload();
        $next = EmploymentFacts::restore($p['facts']);
        if (
            $next->id->value !== (string) $this->aggregateId() || $p['previous_operation_id'] !== $this->operation
            || $p['operation_id'] === $this->operation
            || (($this->employee === null) !== ($p['provenance'] !== 'changed'))
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->employee !== null && ($next->personId->value !== $this->employee->personId->value
            || EmploymentFacts::of($next) === EmploymentFacts::of($this->employee))
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->employee === null) {
            $this->earlierUnknown = $p['provenance'] === 'observed';
        }
        $this->employee = $next;
        $this->operation = $p['operation_id'];
    }
    public function verify(Employee $projection): void
    {
        if ($this->employee !== null && EmploymentFacts::of($this->employee) !== EmploymentFacts::of($projection)) {
            throw new CorruptHistoryException();
        }
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
    public function earlierUnknown(): bool
    {
        return $this->earlierUnknown;
    }
}
