<?php

declare(strict_types=1);

namespace Domain\DegreeAudit;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted;
use Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2 as RequirementAdjustmentDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class RequirementAdjustments extends EventSourcedAggregate
{
    public const MAX_ACTIVE = 500;
    /** @var array<string, array<string, mixed>> */
    private array $active = [];
    private ?string $studentId = null;
    private ?string $programId = null;
    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            (!($event instanceof RequirementAdjustmentWasGranted) &&
                !($event instanceof RequirementAdjustmentWasRevoked)) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenRequirementAdjustmentWasGranted(RequirementAdjustmentWasGranted $e): void
    {
        $p = $e->payload();
        $this->scope($p);
        $key = self::key($p);
        $previous = $this->forKey($key);
        if (
            ($previous["adjustment_id"] ?? null) !== $p["supersedes_adjustment_id"] ||
            isset($this->active[$p["adjustment_id"]])
        ) {
            throw new CorruptHistoryException();
        }
        if ($previous !== null) {
            unset($this->active[$previous["adjustment_id"]]);
        }
        $this->active[$p["adjustment_id"]] = $p;
        if (count($this->active) > self::MAX_ACTIVE) {
            throw new CorruptHistoryException();
        }
    }
    public function whenRequirementAdjustmentWasRevoked(RequirementAdjustmentWasRevoked $e): void
    {
        $p = $e->payload();
        $this->scope($p);
        if (!isset($this->active[$p["adjustment_id"]])) {
            throw new CorruptHistoryException();
        }
        unset($this->active[$p["adjustment_id"]]);
    }
    /** @return array<string, array<string, mixed>> */
    public function active(): array
    {
        return $this->active;
    }
    /** @return array<string, mixed>|null */
    public function forKey(string $key): ?array
    {
        foreach ($this->active as $p) {
            if (self::key($p) === $key) {
                return $p;
            }
        }
        return null;
    }
    /** @param array<string, mixed> $p */
    public static function key(array $p): string
    {
        return ($p["definition"]["kind"] === "waiver" ? "" : $p["requirements_version_id"] . ":") .
        new RequirementAdjustmentDefinition($p["definition"])->key();
    }
    public function assertScope(string $student, string $program): void
    {
        if (
            ($this->studentId !== null && $this->studentId !== $student) ||
            ($this->programId !== null && $this->programId !== $program)
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** @param array<string, mixed> $p */
    private function scope(array $p): void
    {
        if (
            ($this->studentId !== null && $this->studentId !== $p["student_id"]) ||
            ($this->programId !== null && $this->programId !== $p["program_id"])
        ) {
            throw new CorruptHistoryException();
        }
        $this->studentId = $p["student_id"];
        $this->programId = $p["program_id"];
    }
}
