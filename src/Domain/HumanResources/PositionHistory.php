<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Domain\HumanResources\Event\EmployeePositionWasRecorded;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

final class PositionHistory extends EventSourcedAggregate
{
    private ?EmployeePosition $position = null;
    private ?string $operation = null;
    /** @param array<string,mixed> $payload */
    public static function validate(#[\SensitiveParameter] array $payload): void
    {
        if (
            count($payload) !== 5 || array_diff(
                array_keys($payload),
                ['operation_id', 'previous_operation_id', 'action', 'facts', 'intent']
            ) !== []
            || !is_string($payload['operation_id']) || !is_string($payload['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1
            || !in_array($payload['action'], ['assigned', 'end_changed', 'void_changed'], true)
            || !is_array($payload['facts'])
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload['operation_id']);
        if ($payload['previous_operation_id'] !== null) {
            new EntityId($payload['previous_operation_id']);
        }
        EmployeePosition::restore($payload['facts']);
    }
    public function accept(StoredEvent $stored): void
    {
        if (
            !$stored->event instanceof EmployeePositionWasRecorded || $stored->context->origin !== 'command'
            || $stored->context->actor === null
            || $stored->context->effectiveAt === null || $stored->context->reason === null
            || $stored->context->correlation?->value !== ($stored->event->payload()['operation_id'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyRecord($stored->event);
    }
    public function applyRecord(AggregateChanged $event): void
    {
        if (
            !$event instanceof EmployeePositionWasRecorded || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenEmployeePositionWasRecorded(EmployeePositionWasRecorded $event): void
    {
        $p = $event->payload();
        $next = EmployeePosition::restore($p['facts']);
        if (
            $next->id->value !== (string) $this->aggregateId() || $next->version !== $event->playhead()
            || $p['previous_operation_id'] !== $this->operation || $p['operation_id'] === $this->operation
            || (($this->position === null) !== ($p['action'] === 'assigned'))
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->position === null) {
            if ($next->voided) {
                throw new CorruptHistoryException();
            }
        } else {
            $before = $this->position->facts();
            $after = $next->facts();
            $changed = $p['action'] === 'end_changed' ? 'valid_through' : 'voided';
            if ($before[$changed] === $after[$changed] || ($changed === 'valid_through' && $next->voided)) {
                throw new CorruptHistoryException();
            }
            unset($before[$changed], $before['version'], $after[$changed], $after['version']);
            if ($before !== $after) {
                throw new CorruptHistoryException();
            }
        }
        $this->position = $next;
        $this->operation = $p['operation_id'];
    }
    public function position(): ?EmployeePosition
    {
        return $this->position;
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
}
