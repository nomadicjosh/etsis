<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\Event\HiatusCaseWasRecorded;
use Domain\Student\ValueObject\HiatusCaseSchema;

/** A case decision does not repeat the separate activity, academic or financial actions. */
final class HiatusCase extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $current = null;
    private bool $observed = false;

    public function acceptHistorical(DomainEvent $event): void
    {
        if (
            !($event instanceof HiatusCaseWasRecorded) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        HiatusCaseSchema::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenHiatusCaseWasRecorded(
        HiatusCaseWasRecorded $event,
    ): void {
        $p = $event->payload();
        if (
            $p["facts"]["id"] !== (string) $this->aggregateId() ||
            $p["previous_operation_id"] !==
                ($this->current["operation_id"] ?? null) ||
            ($this->current === null &&
                !in_array($p["action"], ["created", "observed"], true))
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->current !== null) {
            $old = $this->current;
            if (
                in_array($p["action"], ["created", "observed"], true) ||
                $old["facts"]["voided"] ||
                $p["facts"]["version"] !== $old["facts"]["version"] + 1 ||
                $p["previous_ends_on"] !== $old["facts"]["ends_on"]
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["id", "student_id", "code", "starts_on"] as $field) {
                if ($p["facts"][$field] !== $old["facts"][$field]) {
                    throw new CorruptHistoryException();
                }
            }
            if ($p["action"] === "returned") {
                if (
                    $old["return_operation_id"] !== null ||
                    ($old["facts"]["ends_on"] !== null &&
                        $p["facts"]["ends_on"] > $old["facts"]["ends_on"])
                ) {
                    throw new CorruptHistoryException();
                }
            } elseif (
                $p["return_operation_id"] !== $old["return_operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            if (
                $p["action"] === "voided" &&
                $p["facts"]["ends_on"] !== $old["facts"]["ends_on"]
            ) {
                throw new CorruptHistoryException();
            }
        }
        if ($p["action"] === "observed") {
            $this->observed = true;
        }
        $this->current = $p;
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        return $this->current;
    }

    public function observed(): bool
    {
        return $this->observed;
    }
}
