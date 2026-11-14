<?php

declare(strict_types=1);

namespace Domain\AcademicRecord;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\AcademicRecord\Event\AcademicWorkPursuitWasRecorded;
use Domain\AcademicRecord\ValueObject\WorkPursuitSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Coursework ownership and explicit receiving-pursuit approvals; grades and awards remain separate facts. */
final class WorkPursuit extends EventSourcedAggregate
{
    /** @var array<string,string>|null */
    private ?array $source = null;
    private ?string $owner = null;
    private ?string $decision = null;
    /** @var array<string,string> */
    private array $reuse = [];

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !$event instanceof AcademicWorkPursuitWasRecorded ||
            !$event->aggregateId()->equals($this->aggregateId()) || $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        WorkPursuitSchema::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenAcademicWorkPursuitWasRecorded(AcademicWorkPursuitWasRecorded $event): void
    {
        $p = $event->payload();
        $target = $p['assignment_id'];
        if (
            $p['source']['credit_id'] !== (string) $this->aggregateId() ||
            $p['previous_decision_id'] !== $this->decision ||
            ($this->source !== null && $this->source != $p['source'])
        ) {
            throw new CorruptHistoryException();
        }
        if ($p['action'] === 'attribute') {
            if ($this->source !== null) {
                throw new CorruptHistoryException();
            }
            $this->source = $p['source'];
            $this->owner = $target;
        } elseif ($this->source === null || $target === $this->owner) {
            throw new CorruptHistoryException();
        } elseif ($p['action'] === 'correct_attribution') {
            $this->owner = $target;
            unset($this->reuse[$target]);
        } elseif ($p['action'] === 'approve_reuse') {
            if (isset($this->reuse[$target]) || count($this->reuse) >= 20) {
                throw new CorruptHistoryException();
            }
            $this->reuse[$target] = $p['decision_id'];
        } else {
            if (!isset($this->reuse[$target])) {
                throw new CorruptHistoryException();
            }
            unset($this->reuse[$target]);
        }
        $this->decision = $p['decision_id'];
    }

    public function appliesTo(string $assignment): bool
    {
        return $this->owner === $assignment || isset($this->reuse[$assignment]);
    }

    public function decision(): ?string
    {
        return $this->decision;
    }

    /** @return array<string,mixed>|null */
    public function projection(): ?array
    {
        if ($this->source === null) {
            return null;
        }
        $reuse = $this->reuse;
        ksort($reuse, SORT_STRING);
        return ['source' => $this->source, 'owner_assignment_id' => $this->owner,
            'reuse' => $reuse, 'decision_id' => $this->decision, 'version' => $this->playhead()];
    }
}
