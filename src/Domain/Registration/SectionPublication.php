<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Bounded incremental replay retains only the most recent definition. */
final class SectionPublication extends EventSourcedAggregate
{
    private ?string $versionId = null;
    /** @var array<string, mixed>|null */
    private ?array $definition = null;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof SectionDefinitionWasPublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenSectionDefinitionWasPublished(
        SectionDefinitionWasPublished $event,
    ): void {
        $p = $event->payload();
        new SectionDefinitionV1($p["definition"]);
        if (
            $p["previous_version_id"] !== $this->versionId ||
            $p["version_id"] === $this->versionId
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->definition !== null) {
            foreach (["course_id", "course_version_id", "term_id"] as $field) {
                if ($p["definition"][$field] !== $this->definition[$field]) {
                    throw new CorruptHistoryException();
                }
            }
        }
        $this->versionId = $p["version_id"];
        $this->definition = $p["definition"];
    }

    /** @return array{version_id: string, version: int, definition: array<string, mixed>}|null */
    public function latest(): ?array
    {
        return $this->versionId === null || $this->definition === null
        ? null
        : [
                "version_id" => $this->versionId,
                "version" => $this->playhead(),
                "definition" => $this->definition,
            ];
    }
}
