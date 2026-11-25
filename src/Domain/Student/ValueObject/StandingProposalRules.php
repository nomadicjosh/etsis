<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Explicit institutional cumulative-GPA floors; no implicit institutional standing is supplied. */
final readonly class StandingProposalRules
{
    public const array BASE_FIELDS = ['enabled', 'minimum_gpa_credits_milli', 'bands'];
    public const array DEFAULT = [
        'enabled' => false, 'minimum_gpa_credits_milli' => 0, 'bands' => [], 'overrides' => [],
    ];

    public function __construct(public array $facts)
    {
        try {
            if (
                count($facts) < 3 || count($facts) > 4
                || array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== []
                || array_diff(self::BASE_FIELDS, array_keys($facts)) !== []
                || !is_bool($facts['enabled']) || !is_int($facts['minimum_gpa_credits_milli'])
                || $facts['minimum_gpa_credits_milli'] < 0 || $facts['minimum_gpa_credits_milli'] > 999999
                || !is_array($facts['bands']) || !array_is_list($facts['bands']) || count($facts['bands']) > 20
                || ($facts['enabled'] && ($facts['bands'] === []
                    || ($facts['bands'][0]['minimum_gpa_milli'] ?? null) !== 0))
            ) {
                throw new \InvalidArgumentException();
            }
            $floor = -1;
            foreach ($facts['bands'] as $band) {
                if (
                    !is_array($band) || count($band) !== 4
                    || array_diff(array_keys($band), ['minimum_gpa_milli', 'definition_id', 'code', 'title']) !== []
                    || !is_int($band['minimum_gpa_milli']) || $band['minimum_gpa_milli'] <= $floor
                    || $band['minimum_gpa_milli'] > 10000 || !is_string($band['definition_id'])
                    || new EntityId($band['definition_id'])->value !== $band['definition_id']
                ) {
                    throw new \InvalidArgumentException();
                }
                StandingPeriods::label($band['code'], $band['title']);
                $floor = $band['minimum_gpa_milli'];
            }
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'bands', 'message' => trans_html(
                'Use up to twenty increasing GPA floors from 0.000 to 10.000, starting at zero when enabled, '
                . 'with complete standing labels and nonnegative minimum GPA credits.'
            )]]);
        }
        self::validateOverrides($facts);
    }

    private static function validateOverrides(array $facts): void
    {
        try {
            if (array_key_exists('overrides', $facts)) {
                if (
                    !is_array($facts['overrides']) || !array_is_list($facts['overrides'])
                    || count($facts['overrides']) > 20
                ) {
                    throw new \InvalidArgumentException();
                }
                $seen = [];
                foreach ($facts['overrides'] as $override) {
                    if (
                        !is_array($override) || count($override) !== 3
                        || array_diff(array_keys($override), ['kind', 'target', 'rules']) !== []
                        || !is_string($override['kind']) || !is_array($override['target'])
                        || !is_array($override['rules']) || count($override['rules']) !== 3
                        || array_diff(array_keys($override['rules']), self::BASE_FIELDS) !== []
                    ) {
                        throw new \InvalidArgumentException();
                    }
                    self::target($override['kind'], $override['target']);
                    $identity = $override['kind'] . ':' . $override['target']['id'];
                    if (isset($seen[$identity])) {
                        throw new \InvalidArgumentException();
                    }
                    $seen[$identity] = true;
                    new self($override['rules']);
                }
            }
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'overrides', 'message' => trans_html(
                'Choose at most twenty distinct program or academic-level targets. Each override needs '
                . 'complete target labels and one GPA rule set, without nested overrides.'
            )]]);
        }
    }

    public static function target(string $kind, array $target): void
    {
        if (
            count($target) !== 3 || array_diff(array_keys($target), ['id', 'code', 'title']) !== []
            || !is_string($target['id'] ?? null) || new EntityId($target['id'])->value !== $target['id']
            || !is_string($target['code'] ?? null) || !is_string($target['title'] ?? null)
        ) {
            throw new \InvalidArgumentException('Use a complete standing policy target.');
        }
        match ($kind) {
            'program' => new \Domain\Catalog\ValueObject\ProgramDefinition($target['code'], $target['title']),
            'academic_level' => \Domain\Catalog\ValueObject\ClassificationSnapshot::fromArray($target),
            default => throw new \InvalidArgumentException('Use a program or academic-level standing policy target.'),
        };
    }

    public function hasLevelOverrides(): bool
    {
        return array_any($this->facts['overrides'] ?? [], static fn(array $r): bool => $r['kind'] === 'academic_level');
    }

    public function programOverride(string $program): ?array
    {
        return array_find($this->facts['overrides'] ?? [], static fn(array $r): bool =>
            $r['kind'] === 'program' && $r['target']['id'] === $program);
    }

    public function selection(StandingProposalScope $scope): array
    {
        $program = $this->programOverride($scope->data['program_id']);
        if ($program !== null) {
            return $program;
        }
        if ($this->hasLevelOverrides() && $scope->data['level_source'] === null) {
            throw new ValidationException([['field' => 'standing_proposal', 'message' => trans_html(
                'Assign a verified published program cohort before applying academic-level standing rules.'
            )]]);
        }
        $level = array_find($this->facts['overrides'] ?? [], static fn(array $r): bool =>
            $r['kind'] === 'academic_level' && $r['target']['id'] === $scope->levelId());
        return $level ?? ['kind' => 'institution', 'target' => null,
            'rules' => array_intersect_key($this->facts, array_flip(self::BASE_FIELDS))];
    }

    public function band(int $gpaMilli): array
    {
        $chosen = null;
        foreach ($this->facts['bands'] as $band) {
            if ($band['minimum_gpa_milli'] <= $gpaMilli) {
                $chosen = $band;
            }
        }
        return $chosen ?? throw new \InvalidArgumentException('A matching standing GPA band is required.');
    }

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([['field' => 'reason',
                'message' => trans_html('Enter a valid reason of up to 1000 characters.')]]);
        }
        return $reason;
    }

    public static function hash(array $data): string
    {
        $canonical = static function (array $values) use (&$canonical): array {
            if (!array_is_list($values)) {
                ksort($values, SORT_STRING);
            }
            return array_map(static fn($value) => is_array($value) ? $canonical($value) : $value, $values);
        };
        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }
}
