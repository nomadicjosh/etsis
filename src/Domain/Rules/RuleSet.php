<?php

declare(strict_types=1);

namespace Domain\Rules;

use InvalidArgumentException;

/** A bounded QueryBuilder JSON tree. Client field names, SQL and executable expressions are never trusted. */
final readonly class RuleSet
{
    /** @var array<string, mixed> */
    public array $tree;

    /**
     * @param array<string, mixed> $tree
     * @param array<string, string> $fields
     */
    public function __construct(array $tree, array $fields)
    {
        $nodes = 0;
        $this->tree = self::group($tree, $fields, 0, $nodes);
    }

    /** @param array<string, mixed> $facts */
    public function matches(array $facts): bool
    {
        return $this->decision($facts) === true;
    }

    /** Missing facts remain unknown, including for exclusions. False wins AND; true wins OR.
     * @param array<string,mixed> $facts
     */
    public function decision(array $facts): ?bool
    {
        return self::evaluate($this->tree, $facts);
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private static function group(array $group, array $fields, int $depth, int &$nodes): array
    {
        if (
            $depth > 4 || ++$nodes > 50 || !in_array($group['condition'] ?? null, ['AND', 'OR'], true)
            || !is_array($group['rules'] ?? null) || !array_is_list($group['rules'])
            || count($group['rules']) < 1 || count($group['rules']) > 20
        ) {
            throw new InvalidArgumentException(
                'Use 1–20 conditions per group, at most 50 conditions and four nested groups.'
            );
        }
        $rules = [];
        foreach ($group['rules'] as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException('Invalid rule.');
            }
            if (isset($rule['condition'])) {
                $rules[] = self::group($rule, $fields, $depth + 1, $nodes);
                continue;
            }
            if (
                ++$nodes > 50 || !is_string($rule['id'] ?? null) || !isset($fields[$rule['id']])
                || !in_array($rule['operator'] ?? null, ['equal', 'not_equal', 'in', 'not_in', 'less', 'greater'], true)
            ) {
                throw new InvalidArgumentException('A rule uses an unsupported field or operator.');
            }
            $operator = $rule['operator'];
            $type = $fields[$rule['id']];
            if (in_array($operator, ['less', 'greater'], true) && $type !== 'integer') {
                throw new InvalidArgumentException('Only numbers can use numeric comparisons.');
            }
            $value = $rule['value'] ?? null;
            if (in_array($operator, ['in', 'not_in'], true)) {
                if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 20) {
                    throw new InvalidArgumentException('A list rule requires 1–20 values.');
                }
                $value = array_map(static fn (mixed $item): string|int|bool => self::value($item, $type), $value);
            } else {
                $value = self::value($value, $type);
            }
            $rules[] = ['id' => $rule['id'], 'operator' => $operator, 'value' => $value];
        }

        return ['condition' => $group['condition'], 'rules' => $rules];
    }

    private static function value(mixed $value, string $type): string|int|bool
    {
        if ($type === 'boolean') {
            return match ($value) {
                true, 1, '1', 'true' => true,
                false, 0, '0', 'false' => false,
                default => throw new InvalidArgumentException('A boolean condition is invalid.'),
            };
        }
        if ($type === 'integer') {
            if ((!is_string($value) && !is_int($value)) || preg_match('/^-?[0-9]{1,9}$/D', (string) $value) !== 1) {
                throw new InvalidArgumentException('A numeric condition is invalid.');
            }

            return (int) $value;
        }
        if (
            !is_string($value) || trim($value) === '' || mb_strlen($value) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $value)
        ) {
            throw new InvalidArgumentException('A text condition is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $facts
     */
    private static function evaluate(array $group, array $facts): ?bool
    {
        $unknown = false;
        foreach ($group['rules'] as $rule) {
            if (isset($rule['condition'])) {
                $match = self::evaluate($rule, $facts);
            } else {
                $actual = $facts[$rule['id']] ?? null;
                $expected = $rule['value'];
                $match = $actual === null ? null : match ($rule['operator']) {
                    'equal' => $actual === $expected,
                    'not_equal' => $actual !== $expected,
                    'in' => in_array($actual, $expected, true),
                    'not_in' => !in_array($actual, $expected, true),
                    'less' => is_int($actual) && $actual < $expected,
                    'greater' => is_int($actual) && $actual > $expected,
                    default => false,
                };
            }
            $unknown = $unknown || $match === null;
            if ($group['condition'] === 'OR' && $match === true) {
                return true;
            }
            if ($group['condition'] === 'AND' && $match === false) {
                return false;
            }
        }

        return $unknown ? null : $group['condition'] === 'AND';
    }
}
