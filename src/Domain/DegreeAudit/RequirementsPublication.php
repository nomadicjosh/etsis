<?php

declare(strict_types=1);

namespace Domain\DegreeAudit;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\DegreeAudit\Event\RequirementsWerePublished;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class RequirementsPublication extends EventSourcedAggregate
{
    private ?string $versionId = null;
    private ?string $programId = null;
    private ?string $yearId = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof RequirementsWerePublished) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenRequirementsWerePublished(RequirementsWerePublished $event): void
    {
        $p = $event->payload();
        if (
            $p["previous_version_id"] !== $this->versionId ||
            ($this->programId !== null && $this->programId !== $p["program_id"]) ||
            ($this->yearId !== null && $this->yearId !== $p["academic_year_id"])
        ) {
            throw new CorruptHistoryException();
        }
        $this->versionId = $p["version_id"];
        $this->programId = $p["program_id"];
        $this->yearId = $p["academic_year_id"];
    }
    public function versionId(): ?string
    {
        return $this->versionId;
    }
    public function assertScope(string $program, string $year): void
    {
        if (
            ($this->programId !== null && $this->programId !== $program) ||
            ($this->yearId !== null && $this->yearId !== $year)
        ) {
            throw new CorruptHistoryException();
        }
    }
}
