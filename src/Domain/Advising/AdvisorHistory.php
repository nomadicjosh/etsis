<?php

declare(strict_types=1);

namespace Domain\Advising;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Advising\Event\StudentAdvisorsWereAssigned;
use Domain\Advising\ValueObject\AdvisorAssignments;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

final class AdvisorHistory extends EventSourcedAggregate
{
    public const array FIELDS = ['student_id', 'pursuit_id', 'operation_id', 'previous_operation_id', 'before_hash', 'rows', 'intent'];
    private ?string $student = null;
    private ?string $operation = null;
    private array $rows = [];
    private array $receipts = [];
    private array $seen = [];

    public static function validate(array $p): void
    {
        if (
            count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== [] ||
            !is_array($p['rows'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        try {
            foreach (['student_id', 'pursuit_id', 'operation_id'] as $field) {
                if (!is_string($p[$field] ?? null)) {
                    throw new \InvalidArgumentException();
                }
                new EntityId($p[$field]);
            }
            if ($p['previous_operation_id'] !== null) {
                new EntityId($p['previous_operation_id']);
            }
            foreach (['before_hash', 'intent'] as $field) {
                if (!is_string($p[$field]) || preg_match('/^[a-f0-9]{64}$/D', $p[$field]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            new AdvisorAssignments($p['rows']);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        if (
            !$event instanceof StudentAdvisorsWereAssigned || !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 || $stored->context->origin !== 'command' ||
            $stored->context->actor === null || $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== ($event->payload()['operation_id'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $p = $event->payload();
        $this->receipts[$this->operation] = ['actor' => $stored->context->actor->value, 'intent' => $p['intent'],
            'result' => ['operation_id' => $this->operation, 'version' => $this->playhead, 'status' => 'updated']];
    }
    public function whenStudentAdvisorsWereAssigned(StudentAdvisorsWereAssigned $event): void
    {
        self::validate($p = $event->payload());
        if (
            $p['pursuit_id'] !== (string) $this->aggregateId() ||
            ($this->student !== null && $p['student_id'] !== $this->student) ||
            $p['previous_operation_id'] !== $this->operation || isset($this->seen[$p['operation_id']]) ||
            $p['before_hash'] !== new AdvisorAssignments($this->rows)->fingerprint()
        ) {
            throw new CorruptHistoryException();
        }
        $next = new AdvisorAssignments($p['rows'])->rows;
        if ($next === $this->rows) {
            throw new CorruptHistoryException();
        }
        $this->rows = $next;
        $this->student = $p['student_id'];
        $this->operation = $p['operation_id'];
        $this->seen[$this->operation] = true;
    }
    public function rows(): array
    {
        return $this->rows;
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
    public function receipt(EntityId $operation, EntityId $actor, string $intent): ?array
    {
        $receipt = $this->receipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt['actor'] !== $actor->value || !hash_equals($receipt['intent'], $intent)) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                'This advising confirmation belongs to another instruction.'
            );
        }
        return $receipt['result'];
    }
}
