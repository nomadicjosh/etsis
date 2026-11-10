<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\SectionOfferingVersionWasAmended;
use Domain\Registration\Event\SectionOfferingVersionWasSelected;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Incremental replay of initial selection and explicit future-registration amendments. */
final class SectionOfferingBinding extends EventSourcedAggregate
{
    private ?string $publication = null;

    public function whenSectionOfferingVersionWasSelected(SectionOfferingVersionWasSelected $event): void
    {
        if ($this->publication !== null) {
            throw new CorruptHistoryException();
        }
        $this->publication = $event->payload()["publication_id"];
    }

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !in_array(
                $event::class,
                [SectionOfferingVersionWasSelected::class, SectionOfferingVersionWasAmended::class],
                true,
            ) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenSectionOfferingVersionWasAmended(SectionOfferingVersionWasAmended $event): void
    {
        $p = $event->payload();
        if (
            $this->publication === null ||
            $p["previous_publication_id"] !== $this->publication ||
            $p["publication_id"] === $this->publication
        ) {
            throw new CorruptHistoryException();
        }
        $this->publication = $p["publication_id"];
    }

    public function publication(): ?string
    {
        return $this->publication;
    }
}
