<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Catalog\Event\ProgramDefinitionWasPublished;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Only the last publication identity is retained while consuming verified pages. */
final class ProgramPublication extends EventSourcedAggregate
{
    private ?string $versionId = null;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof ProgramDefinitionWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenProgramDefinitionWasPublished(ProgramDefinitionWasPublished $event): void
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
