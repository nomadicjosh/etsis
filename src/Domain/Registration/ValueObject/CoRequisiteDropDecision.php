<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;

/** Restricted evidence; only the minimized summary may leave an authorized registration command. */
final readonly class CoRequisiteDropDecision implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        if (
            count($facts) !== 4 ||
            array_diff(array_keys($facts), ['student_id', 'policy', 'dropping', 'checks']) !== [] ||
            !is_string($facts['student_id']) || !is_array($facts['dropping']) ||
            !array_is_list($facts['dropping']) || count($facts['dropping']) < 1 || count($facts['dropping']) > 100 ||
            count(array_unique($facts['dropping'])) !== count($facts['dropping']) ||
            !is_array($facts['checks']) || !array_is_list($facts['checks']) || count($facts['checks']) > 100
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($facts['student_id']);
        foreach ($facts['dropping'] as $section) {
            if (!is_string($section)) {
                throw new CorruptHistoryException();
            }
            new EntityId($section);
        }
        CoRequisiteDecision::validatePolicy($facts['policy']);
        $seen = [];
        foreach ($facts['checks'] as $check) {
            if (
                !is_array($check) || count($check) !== 4 ||
                array_diff(array_keys($check), [
                    'enrollment_id', 'section_id', 'registration_version', 'assessment',
                ]) !== [] ||
                !is_string($check['enrollment_id']) || !is_string($check['section_id']) ||
                !is_int($check['registration_version']) || $check['registration_version'] < 1 ||
                isset($seen[$check['enrollment_id']]) || in_array($check['section_id'], $facts['dropping'], true) ||
                !is_array($check['assessment'])
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($check['enrollment_id']);
            new EntityId($check['section_id']);
            $seen[$check['enrollment_id']] = true;
            $decision = new CoRequisiteDecision($check['assessment']);
            if ($decision->facts['student_id'] !== $facts['student_id']) {
                throw new CorruptHistoryException();
            }
        }
        if (strlen(json_encode($facts, JSON_THROW_ON_ERROR)) > 131072) {
            throw new HistoryReadLimitException('This dependency assessment exceeds its retained evidence limit.');
        }
    }
    public function summary(): array
    {
        $required = [];
        foreach ($this->facts['checks'] as $check) {
            if (!new CoRequisiteDecision($check['assessment'])->isMet()) {
                $required[] = $check['section_id'];
            }
        }
        return ['blocked' => $required !== [] && $this->facts['policy']['drop_mode'] === 'block',
            'warning' => $required !== [], 'required_sections' => $required, 'revision' => $this->revision(),
            'policy_revision' => $this->facts['policy']['revision']];
    }
    public function blocked(): bool
    {
        return $this->summary()['blocked'];
    }
    public function revision(): string
    {
        return hash('sha256', json_encode($this->facts, JSON_THROW_ON_ERROR));
    }
    public function __debugInfo(): array
    {
        return ['dependencies' => '[protected decision]'];
    }
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
