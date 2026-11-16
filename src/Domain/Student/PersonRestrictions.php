<?php

declare(strict_types=1);

namespace Domain\Student;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\PersonRestrictionsWereRecorded;
use Domain\Student\Event\RestrictionOfficeWasChanged;
use Domain\Student\ValueObject\StudentActivitySchema;

/** One person's hold decisions, independent of student, employment and account roles. */
final class PersonRestrictions extends EventSourcedAggregate
{
    public const int MAX_RESTRICTIONS = 500;
    public const int MAX_EVENTS = 5000;
    /** @var array<string,Restriction> */
    private array $restrictions = [];
    /** @var array<string,string> Recorded current office; an explicit handoff preserves preceding ownership. */
    private array $offices = [];
    private ?string $lastOperation = null;

    /** @param array<string,mixed> $p */
    public static function validate(array $p): void
    {
        $keys = ['operation_id', 'previous_operation_id', 'action', 'facts', 'intent', 'authority'];
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
        if ($p['action'] === 'observed') {
            if ($p['authority'] !== null) {
                throw new CorruptHistoryException();
            }
        } else {
            self::authority($p['authority']);
        }
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
            !($event instanceof PersonRestrictionsWereRecorded || $event instanceof RestrictionOfficeWasChanged)
            || !$event->aggregateId()->equals($this->aggregateId())
            || $event->playhead() !== $this->playhead() + 1 || $event->playhead() > self::MAX_EVENTS
        ) {
            throw new CorruptHistoryException();
        }
        if ($event instanceof RestrictionOfficeWasChanged) {
            self::validateHandoff($event->payload());
        } else {
            self::validate($event->payload());
        }
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
            if ($p['action'] !== 'observed') {
                $office = $p['authority']['office_id'];
                if (isset($this->offices[$hold->id->value]) && $this->offices[$hold->id->value] !== $office) {
                    throw new CorruptHistoryException();
                }
                $this->offices[$hold->id->value] = $office;
            }
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

    /** @param array<string,mixed> $p */
    public static function validateHandoff(array $p): void
    {
        $keys = ['operation_id', 'previous_operation_id', 'restriction_id', 'from_authority', 'to_authority', 'intent'];
        if (
            count($p) !== count($keys) || array_diff(array_keys($p), $keys) !== []
            || !is_string($p['intent'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $p['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['operation_id', 'previous_operation_id', 'restriction_id'] as $key) {
            if (!is_string($p[$key] ?? null)) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        self::handoffAuthority($p['from_authority']);
        self::handoffAuthority($p['to_authority']);
        if (
            $p['from_authority']['office_id'] === $p['to_authority']['office_id']
            || $p['operation_id'] === $p['previous_operation_id']
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function whenRestrictionOfficeWasChanged(RestrictionOfficeWasChanged $event): void
    {
        $p = $event->payload();
        $id = $p['restriction_id'];
        if (
            $p['previous_operation_id'] !== $this->lastOperation || !isset($this->restrictions[$id])
            || (isset($this->offices[$id]) && $this->offices[$id] !== $p['from_authority']['office_id'])
        ) {
            throw new CorruptHistoryException();
        }
        $this->offices[$id] = $p['to_authority']['office_id'];
        $this->lastOperation = $p['operation_id'];
    }

    public function lastOperation(): ?string
    {
        return $this->lastOperation;
    }

    /** @return array<string,string> */
    public function offices(): array
    {
        return $this->offices;
    }

    private static function authority(mixed $a): void
    {
        $keys = ['office_id', 'office_code', 'office_name', 'mode', 'delegation_id', 'delegation_revision'];
        if (
            !is_array($a) || count($a) !== count($keys) || array_diff(array_keys($a), $keys) !== []
            || !is_string($a['office_id'] ?? null) || !is_string($a['office_code'] ?? null)
            || !is_string($a['office_name'] ?? null)
            || !in_array($a['mode'] ?? null, ['administrative', 'delegated'], true)
            || ($a['mode'] === 'administrative'
                ? $a['delegation_id'] !== null || $a['delegation_revision'] !== null
                : !is_string($a['delegation_id']) || !is_int($a['delegation_revision'])
                    || $a['delegation_revision'] < 0)
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($a['office_id']);
        if ($a['delegation_id'] !== null) {
            new EntityId($a['delegation_id']);
        }
        foreach (['office_code' => 22, 'office_name' => 120] as $key => $max) {
            if (
                trim($a[$key]) === '' || !mb_check_encoding($a[$key], 'UTF-8') || mb_strlen($a[$key]) > $max
                || preg_match('/[\x00-\x1F\x7F]/', $a[$key])
            ) {
                throw new CorruptHistoryException();
            }
        }
    }

    private static function handoffAuthority(mixed $a): void
    {
        if (
            !is_array($a) || count($a) !== 8 || !array_key_exists('starts_on', $a)
            || !array_key_exists('ends_on', $a)
        ) {
            throw new CorruptHistoryException();
        }
        self::authority(array_diff_key($a, ['starts_on' => true, 'ends_on' => true]));
        if ($a['mode'] === 'administrative') {
            if ($a['starts_on'] !== null || $a['ends_on'] !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (
            !is_string($a['starts_on']) || !StudentActivitySchema::date($a['starts_on'])
            || ($a['ends_on'] !== null && (!is_string($a['ends_on']) || !StudentActivitySchema::date($a['ends_on'])
                || $a['ends_on'] < $a['starts_on']))
        ) {
            throw new CorruptHistoryException();
        }
    }
}
