<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

/** Operational checklist definitions and progress, separate from an admissions decision or academic waiver. */
final class CaseworkChecklist
{
    public const int LIMIT = 30;
    public const array DEFINITION_FIELDS = ['key', 'label', 'instructions', 'needed', 'visible'];
    public const array STATES = ['pending', 'received', 'complete', 'not_needed'];

    /** @param array<mixed> $input
     * @return list<array<string,mixed>> */
    public static function items(#[\SensitiveParameter] array $input, bool $definition = false): array
    {
        if (!array_is_list($input) || count($input) > self::LIMIT) {
            self::invalid('items', 'Use up to thirty checklist items or follow-up tasks.');
        }
        $items = [];
        $seen = [];
        $allowed = $definition ? self::DEFINITION_FIELDS : [...self::DEFINITION_FIELDS, 'status', 'due_on'];
        foreach ($input as $index => $row) {
            if (!is_array($row) || array_diff(array_keys($row), $allowed) !== []) {
                self::invalid('items', 'Review the checklist fields.');
            }
            $key = self::text($row['key'] ?? '', "items.$index.key", 40, true);
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $key) !== 1 || isset($seen[$key])) {
                self::invalid('items', 'Each checklist item needs a separate reference.');
            }
            $seen[$key] = true;
            foreach (['needed', 'visible'] as $flag) {
                if (!is_bool($row[$flag] ?? null)) {
                    self::invalid("items.$index.$flag", 'Choose whether this item is needed and applicant-visible.');
                }
            }
            $item = ['key' => $key, 'label' => self::text($row['label'] ?? '', "items.$index.label", 191, true),
                'instructions' => self::text($row['instructions'] ?? '', "items.$index.instructions", 1000),
                'needed' => $row['needed'], 'visible' => $row['visible']];
            if (!$definition) {
                if (!in_array($row['status'] ?? null, self::STATES, true)) {
                    self::invalid("items.$index.status", 'Choose a checklist progress status.');
                }
                $item['status'] = $row['status'];
                $item['due_on'] = self::date($row['due_on'] ?? '', "items.$index.due_on");
            }
            $items[] = $item;
        }
        return $items;
    }

    public static function text(
        #[\SensitiveParameter] mixed $value,
        string $field,
        int $limit,
        bool $required = false
    ): string {
        if (
            !is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
            || ($required && trim($value) === '')
        ) {
            self::invalid($field, 'Enter valid text within the field limit.');
        }
        return trim($value);
    }

    public static function date(#[\SensitiveParameter] mixed $value, string $field): string
    {
        if ($value === '') {
            return '';
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }
        self::invalid($field, 'Choose a valid calendar date or leave it blank.');
    }

    public static function invalid(string $field, string $message): never
    {
        throw new ValidationException([['field' => $field, 'message' => $message]]);
    }
}
