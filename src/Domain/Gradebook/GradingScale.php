<?php

declare(strict_types=1);

namespace Domain\Gradebook;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Gradebook\Event\GradingScaleWasChanged;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class GradingScale extends EventSourcedAggregate
{
    private ?string $versionId = null;
    /** @var array<string,mixed>|null */
    private ?array $definition = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof GradingScaleWasChanged) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenGradingScaleWasChanged(GradingScaleWasChanged $event): void
    {
        $p = $event->payload();
        if ($p["previous_version_id"] !== $this->versionId) {
            throw new CorruptHistoryException();
        }
        $this->versionId = $p["version_id"];
        $this->definition = $p["definition"];
    }
    public function versionId(): ?string
    {
        return $this->versionId;
    }
    /** @return array<string,mixed>|null */
    public function definition(): ?array
    {
        return $this->definition;
    }
}
