<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\SectionMeetingPlanWasPublished;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Replay retains only the current definition; earlier publications remain separate facts. */
final class SectionMeetingPlan extends EventSourcedAggregate
{
    private ?string $publication = null;
    private ?SectionMeetingPlanDefinition $definition = null;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !$event instanceof SectionMeetingPlanWasPublished || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenSectionMeetingPlanWasPublished(SectionMeetingPlanWasPublished $event): void
    {
        $payload = $event->payload();
        $definition = new SectionMeetingPlanDefinition($payload['definition']);
        if (
            $payload['previous_version_id'] !== $this->publication || $payload['version_id'] === $this->publication
            || $definition->data['section_id'] !== (string) $this->aggregateId()
        ) {
            throw new CorruptHistoryException();
        }
        $this->publication = $payload['version_id'];
        $this->definition = $definition;
    }

    public function publication(): ?string
    {
        return $this->publication;
    }

    public function definition(): ?SectionMeetingPlanDefinition
    {
        return $this->definition;
    }
}
