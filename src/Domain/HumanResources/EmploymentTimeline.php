<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Domain\HumanResources\Event\EmploymentDatesWereRecorded;
use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

final class EmploymentTimeline extends EventSourcedAggregate
{
    private ?EmploymentDates $dates = null;
    private ?string $operation = null;

    /** @param array<string,mixed> $p */
    public static function validate(#[\SensitiveParameter] array $p): void
    {
        if (
            count($p) !== 5 || array_diff(
                array_keys($p),
                ['operation_id', 'previous_operation_id', 'period_id', 'facts', 'intent']
            ) !== []
            || !is_string($p['intent']) || preg_match('/^[a-f0-9]{64}$/D', $p['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p['operation_id']);
        new EntityId($p['period_id']);
        if ($p['previous_operation_id'] !== null) {
            new EntityId($p['previous_operation_id']);
        }
        EmploymentDates::restore($p['facts']);
    }
    public function accept(StoredEvent $stored): void
    {
        if (
            !$stored->event instanceof EmploymentDatesWereRecorded || $stored->context->origin !== 'command'
            || $stored->context->actor === null || $stored->context->reason === null
            || $stored->context->effectiveAt === null
            || $stored->context->correlation?->value !== ($stored->event->payload()['operation_id'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyRecord($stored->event);
    }
    public function applyRecord(AggregateChanged $event): void
    {
        if (
            !$event instanceof EmploymentDatesWereRecorded || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenEmploymentDatesWereRecorded(EmploymentDatesWereRecorded $event): void
    {
        $p = $event->payload();
        $next = EmploymentDates::restore($p['facts']);
        $before = $this->dates ?? new EmploymentDates(new EntityId((string) $this->aggregateId()), [], 0);
        $old = array_column($before->periods, null, 'id');
        $after = array_column($next->periods, null, 'id');
        if (
            $next->employee->value !== (string) $this->aggregateId() || $next->version !== $event->playhead()
            || $p['previous_operation_id'] !== $this->operation || $p['operation_id'] === $this->operation
            || !isset($after[$p['period_id']]) || ($old[$p['period_id']] ?? null) === $after[$p['period_id']]
        ) {
            throw new CorruptHistoryException();
        }
        if (!isset($old[$p['period_id']]) && $after[$p['period_id']]['voided']) {
            throw new CorruptHistoryException();
        }
        unset($old[$p['period_id']], $after[$p['period_id']]);
        if ($old !== $after) {
            throw new CorruptHistoryException();
        }
        $this->dates = $next;
        $this->operation = $p['operation_id'];
    }
    public function dates(): EmploymentDates
    {
        return $this->dates ?? new EmploymentDates(new EntityId((string) $this->aggregateId()), [], 0);
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
}
