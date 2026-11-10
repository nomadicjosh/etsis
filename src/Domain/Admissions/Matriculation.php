<?php

declare(strict_types=1);

namespace Domain\Admissions;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Admissions\Event\StudentWasMatriculated;
use Domain\Admissions\ValueObject\MatriculationSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** One immutable creation decision; later student/program decisions have their own streams. */
final class Matriculation extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $facts = null;
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== 1 ||
            $this->playhead() !== 0
        ) {
            throw new CorruptHistoryException();
        }
        MatriculationSchema::validate($event::class, $event->payload());
        if ($event->payload()["application_id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = 1;
    }
    public function whenStudentWasMatriculated(StudentWasMatriculated $event): void
    {
        $this->facts = $event->payload();
    }
    /** @return array<string,mixed>|null */
    public function facts(): ?array
    {
        return $this->facts;
    }
}
