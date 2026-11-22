<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\HumanResources\Event\PrimaryPositionWasRecorded;
use Domain\HumanResources\ValueObject\PrimaryPosition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

final class PrimaryPositionHistory extends EventSourcedAggregate
{
    private ?PrimaryPosition $designation = null;
    private ?string $operation = null;

    /** @param array<string,mixed> $payload */
    public static function validate(#[\SensitiveParameter] array $payload): void
    {
        if (
            count($payload) !== 4 ||
            array_diff(array_keys($payload), [
                "operation_id",
                "previous_operation_id",
                "facts",
                "intent",
            ]) !== [] ||
            !is_string($payload["intent"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $payload["intent"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload["operation_id"]);
        if ($payload["previous_operation_id"] !== null) {
            new EntityId($payload["previous_operation_id"]);
        }
        PrimaryPosition::restore($payload["facts"]);
    }
    public function accept(StoredEvent $stored): void
    {
        if (
            !($stored->event instanceof PrimaryPositionWasRecorded) ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !==
                ($stored->event->payload()["operation_id"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyRecord($stored->event);
    }
    public function applyRecord(PrimaryPositionWasRecorded $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenPrimaryPositionWasRecorded(PrimaryPositionWasRecorded $event): void
    {
        $payload = $event->payload();
        $next = PrimaryPosition::restore($payload["facts"]);
        if (
            $next->employee->value !== (string) $this->aggregateId() ||
            $next->version !== $event->playhead() ||
            $payload["previous_operation_id"] !== $this->operation ||
            $payload["operation_id"] === $this->operation ||
            $next->selection === $this->designation()->selection
        ) {
            throw new CorruptHistoryException();
        }
        $this->designation = $next;
        $this->operation = $payload["operation_id"];
    }
    public function designation(): PrimaryPosition
    {
        return $this->designation ??
        new PrimaryPosition(new EntityId((string) $this->aggregateId()), null, 0);
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
}
