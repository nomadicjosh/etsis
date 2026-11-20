<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Internal accepted basis. Ordinary responses expose only its minimized summary. */
final readonly class CoRequisiteDecision implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        $keys = ['student_id', 'assignment_id', 'term_id', 'required', 'policy', 'support'];
        if (count($facts) !== count($keys) || array_diff(array_keys($facts), $keys) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['student_id', 'assignment_id', 'term_id'] as $key) {
            self::id($facts[$key]);
        }
        $required = $facts['required'];
        if (
            !is_array($required) || !array_is_list($required) || count($required) > 20 ||
            count(array_unique($required)) !== count($required)
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($required as $id) {
            self::id($id);
        }
        $sorted = $required;
        sort($sorted, SORT_STRING);
        self::validatePolicy($facts['policy']);
        if (
            $sorted !== $required || !is_array($facts['support']) ||
            !array_is_list($facts['support']) || count($facts['support']) > count($required)
        ) {
            throw new CorruptHistoryException();
        }
        $seen = [];
        foreach ($facts['support'] as $ref) {
            $keys = ['course_id', 'kind', 'origin', 'source_id', 'credit_id', 'section_id',
                'academic_version', 'registration_version', 'pursuit_version', 'operation_id'];
            if (
                !is_array($ref) || count($ref) !== count($keys) || array_diff(array_keys($ref), $keys) !== [] ||
                !in_array($ref['course_id'], $required, true) || isset($seen[$ref['course_id']]) ||
                !in_array($ref['kind'], ['completed', 'concurrent', 'planned', 'coordinated'], true)
            ) {
                throw new CorruptHistoryException();
            }
            $seen[$ref['course_id']] = true;
            foreach (['source_id', 'credit_id', 'section_id', 'operation_id'] as $key) {
                if ($ref[$key] !== null) {
                    self::id($ref[$key]);
                }
            }
            self::id($ref['source_id']);
            if (in_array($ref['kind'], ['completed', 'concurrent'], true)) {
                if (
                    !in_array($ref['origin'], ['institutional', 'transfer'], true) ||
                    $ref['credit_id'] === null || $ref['operation_id'] !== null ||
                    !self::version($ref['academic_version'], 1) || !self::version($ref['pursuit_version'], 1) ||
                    ($ref['origin'] === 'institutional' ? !self::version($ref['registration_version'], 0) :
                        $ref['registration_version'] !== null) ||
                    ($ref['kind'] === 'concurrent' && ($ref['origin'] !== 'institutional' ||
                        $ref['section_id'] === null || $ref['registration_version'] < 1)) ||
                    ($ref['kind'] === 'completed' && !$facts['policy']['prior_completion'])
                ) {
                    throw new CorruptHistoryException();
                }
            } elseif (
                $ref['origin'] !== null || $ref['credit_id'] !== null || $ref['section_id'] === null ||
                $ref['academic_version'] !== null || $ref['registration_version'] !== null ||
                $ref['pursuit_version'] !== null ||
                ($ref['kind'] === 'coordinated' ? $ref['operation_id'] === null : $ref['operation_id'] !== null)
            ) {
                throw new CorruptHistoryException();
            }
        }
    }
    public static function validatePolicy(mixed $policy): void
    {
        if (
            !is_array($policy) || count($policy) !== 3 ||
            !is_bool($policy['prior_completion'] ?? null) ||
            !in_array($policy['drop_mode'] ?? null, ['block', 'warn'], true) ||
            !is_int($policy['revision'] ?? null) || $policy['revision'] < 0 || $policy['revision'] > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
    }
    public function isMet(): bool
    {
        return count($this->facts['support']) === count($this->facts['required']);
    }
    public function revision(): string
    {
        return hash('sha256', json_encode($this->facts, JSON_THROW_ON_ERROR));
    }
    public function summary(): array
    {
        $support = array_column($this->facts['support'], 'kind', 'course_id');
        return ['status' => $this->isMet() ? 'met' : 'missing', 'revision' => $this->revision(),
            'prior_completion' => $this->facts['policy']['prior_completion'],
            'courses' => array_map(
                static fn(string $id): string => $support[$id] ?? 'missing',
                $this->facts['required'],
            )];
    }
    public function retained(): array
    {
        if (!$this->isMet() || in_array('planned', array_column($this->facts['support'], 'kind'), true)) {
            throw new CorruptHistoryException();
        }
        return $this->facts;
    }
    public static function fromRetained(#[\SensitiveParameter] array $facts): self
    {
        $self = new self($facts);
        $self->retained();
        return $self;
    }
    private static function version(mixed $value, int $minimum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= 2000000000;
    }
    private static function id(mixed $value): void
    {
        try {
            if (!is_string($value)) {
                throw new \InvalidArgumentException();
            }
            new EntityId($value);
        } catch (\InvalidArgumentException) {
            throw new CorruptHistoryException();
        }
    }
    public function __debugInfo(): array
    {
        return ['corequisites' => '[protected decision]'];
    }
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
