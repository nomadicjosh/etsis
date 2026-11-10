<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Registration\Event\SectionOfferingVersionWasSelected;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Initial selection only; subsequent amendments require a separate reviewed workflow. */
final class SectionOfferingBinding extends EventSourcedAggregate
{
    private ?string $publication = null;

    public function whenSectionOfferingVersionWasSelected(SectionOfferingVersionWasSelected $event): void
    {
        if ($this->publication !== null) {
            throw new CorruptHistoryException();
        }
        $this->publication = $event->payload()['publication_id'];
    }

    public function publication(): ?string
    {
        return $this->publication;
    }
}
