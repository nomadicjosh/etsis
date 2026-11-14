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
    private ?string $specializationId = null;
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
            ($this->yearId !== null && $this->yearId !== $p["academic_year_id"]) ||
            ($this->versionId !== null && $this->specializationId !== $event->metaParam('specialization_id'))
        ) {
            throw new CorruptHistoryException();
        }
        $this->versionId = $p["version_id"];
        $this->programId = $p["program_id"];
        $this->yearId = $p["academic_year_id"];
        $this->specializationId = $event->metaParam('specialization_id');
    }
    public function versionId(): ?string
    {
        return $this->versionId;
    }
    public function assertScope(string $program, string $year, ?string $specialization = null): void
    {
        if (
            ($this->programId !== null && $this->programId !== $program) ||
            ($this->yearId !== null && $this->yearId !== $year) ||
            ($this->versionId !== null && $this->specializationId !== $specialization)
        ) {
            throw new CorruptHistoryException();
        }
    }
}
