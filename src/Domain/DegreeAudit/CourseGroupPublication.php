<?php

declare(strict_types=1);

namespace Domain\DegreeAudit;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\DegreeAudit\Event\CourseGroupWasPublished;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Replays one verified page at a time without retaining all earlier definitions in memory. */
final class CourseGroupPublication extends EventSourcedAggregate
{
    private ?string $versionId = null;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof CourseGroupWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenCourseGroupWasPublished(CourseGroupWasPublished $event): void
    {
        if ($event->payload()["previous_version_id"] !== $this->versionId) {
            throw new CorruptHistoryException();
        }
        $this->versionId = $event->payload()["version_id"];
    }

    public function versionId(): ?string
    {
        return $this->versionId;
    }
}
