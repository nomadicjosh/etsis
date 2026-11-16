<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\PersonRestrictionsWereRecorded;
use Domain\Student\ValueObject\StudentActivitySchema;

/** One person's hold decisions, independent of student, employment and account roles. */
final class PersonRestrictions extends EventSourcedAggregate
{
    public const int MAX_RESTRICTIONS = 500;
    public const int MAX_EVENTS = 5000;
    /** @var array<string,Restriction> */
    private array $restrictions = [];
    private ?string $lastOperation = null;

    /** @param array<string,mixed> $p */
    public static function validate(array $p): void
    {
        $keys = ['operation_id', 'previous_operation_id', 'action', 'facts', 'intent'];
        if (
            count($p) !== count($keys) || array_diff(array_keys($p), $keys) !== []
            || !is_string($p['operation_id'] ?? null)
            || !in_array($p['action'] ?? null, ['observed', 'placed', 'ended'], true)
            || !is_array($p['facts'] ?? null) || !array_is_list($p['facts'])
            || count($p['facts']) > self::MAX_RESTRICTIONS
            || ($p['action'] === 'observed'
                ? $p['previous_operation_id'] !== null || $p['intent'] !== null
                : !is_string($p['previous_operation_id']) || count($p['facts']) !== 1
                    || !is_string($p['intent']) || preg_match('/^[a-f0-9]{64}$/D', $p['intent']) !== 1)
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p['operation_id']);
        if ($p['previous_operation_id'] !== null) {
            new EntityId($p['previous_operation_id']);
        }
        foreach ($p['facts'] as $f) {
            $fields = ['id', 'person_id', 'code', 'severity', 'starts_on', 'ends_on', 'blocks_staff'];
            if (
                !is_array($f) || count($f) !== count($fields) || array_diff(array_keys($f), $fields) !== []
                || !is_string($f['id'] ?? null) || !is_string($f['person_id'] ?? null)
                || !is_string($f['code'] ?? null) || !is_int($f['severity'] ?? null)
                || !is_bool($f['blocks_staff'] ?? null) || !is_string($f['starts_on'] ?? null)
                || !StudentActivitySchema::date($f['starts_on'])
                || ($f['ends_on'] !== null && (!is_string($f['ends_on'])
                    || !StudentActivitySchema::date($f['ends_on']) || $f['ends_on'] < $f['starts_on']))
            ) {
                throw new CorruptHistoryException();
            }
            Restriction::fromFacts($f);
        }
    }

    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event instanceof PersonRestrictionsWereRecorded
            || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1 || $event->playhead() > self::MAX_EVENTS
        ) {
            throw new CorruptHistoryException();
        }
        self::validate($event->payload());
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }

    public function whenPersonRestrictionsWereRecorded(PersonRestrictionsWereRecorded $event): void
    {
        $p = $event->payload();
        if (
            $p['previous_operation_id'] !== $this->lastOperation
            || ($p['action'] === 'observed') !== ($this->playhead() === 0)
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($p['facts'] as $f) {
            $hold = Restriction::fromFacts($f);
            $before = $this->restrictions[$hold->id->value] ?? null;
            if (
                $hold->personId->value !== (string) $this->aggregateId()
                || ($p['action'] !== 'ended' && $before !== null)
            ) {
                throw new CorruptHistoryException();
            }
            if ($p['action'] === 'ended') {
                $expected = $before?->facts();
                if (
                    $expected === null || $hold->dates->end === null
                    || ($before->dates->end !== null && $hold->dates->end > $before->dates->end)
                ) {
                    throw new CorruptHistoryException();
                }
                $expected['ends_on'] = $f['ends_on'];
                if ($hold->facts() !== $expected) {
                    throw new CorruptHistoryException();
                }
            }
            $this->restrictions[$hold->id->value] = $hold;
        }
        if (count($this->restrictions) > self::MAX_RESTRICTIONS) {
            throw new CorruptHistoryException();
        }
        $this->lastOperation = $p['operation_id'];
    }

    /** @return array<string,Restriction> */
    public function restrictions(): array
    {
        return $this->restrictions;
    }

    public function lastOperation(): ?string
    {
        return $this->lastOperation;
    }
}
