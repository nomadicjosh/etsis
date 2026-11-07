<?php

declare(strict_types=1);

namespace Domain\Admissions;

use Domain\Rules\RuleSet;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class ApplicationForm
{
    /** @var list<array<string, mixed>> */
    public array $fields;

    /** @param array<int, array<string, mixed>> $fields */
    public function __construct(
        public EntityId $id,
        public EntityId $programId,
        public string $name,
        array $fields,
        public string $status = 'draft',
        public int $version = 0,
    ) {
        if (
            trim($name) === '' || mb_strlen($name) > 191 || count($fields) < 1 || count($fields) > 40
            || !array_is_list($fields) || !in_array($status, ['draft', 'published'], true) || $version < 0
        ) {
            throw new InvalidArgumentException('A named application form with 1–40 fields is required.');
        }
        $known = [];
        $clean = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            $type = $field['type'] ?? null;
            $label = $field['label'] ?? null;
            $help = $field['help'] ?? '';
            if (
                !is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1 || isset($known[$key])
                || !in_array($type, ['text', 'textarea', 'email', 'date', 'select', 'number', 'checkbox'], true)
                || !is_string($label) || trim($label) === '' || mb_strlen($label) > 120
                || !is_string($help) || mb_strlen($help) > 300 || preg_match('/[\x00-\x1f\x7f]/', $label . $help)
                || !is_bool($field['required'] ?? false)
            ) {
                throw new InvalidArgumentException('A form field is invalid or uses a duplicate key.');
            }
            $options = $field['options'] ?? [];
            if (
                !is_array($options) || !array_is_list($options) || count($options) > 30
                || ($type === 'select' && $options === [])
            ) {
                throw new InvalidArgumentException('Select fields require 1–30 options.');
            }
            foreach ($options as $option) {
                if (
                    !is_string($option) || trim($option) === '' || mb_strlen($option) > 120
                    || preg_match('/[\x00-\x1f\x7f]/', $option)
                ) {
                    throw new InvalidArgumentException('A select option is invalid.');
                }
            }
            $condition = null;
            if (isset($field['show_when'])) {
                if (!is_array($field['show_when'])) {
                    throw new InvalidArgumentException('A field condition is invalid.');
                }
                $condition = new RuleSet($field['show_when'], $known)->tree;
            }
            $known[$key] = match ($type) {
                'number' => 'integer', 'checkbox' => 'boolean', default => 'string'
            };
            $clean[] = ['key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'required' => $field['required'] ?? false, 'options' => array_values(array_unique($options)),
                'show_when' => $condition];
        }
        $this->fields = $clean;
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, string|int|bool>
     */
    public function validate(array $answers): array
    {
        $result = [];
        $errors = [];
        $known = array_column($this->fields, 'key');
        if (array_diff(array_keys($answers), $known) !== []) {
            throw new InvalidArgumentException('The application contains an unknown field.');
        }
        foreach ($this->fields as $field) {
            if ($field['show_when'] !== null) {
                $types = [];
                foreach ($this->fields as $item) {
                    $types[$item['key']] = match ($item['type']) {
                        'number' => 'integer', 'checkbox' => 'boolean', default => 'string',
                    };
                }
                if (!new RuleSet($field['show_when'], $types)->matches($result)) {
                    continue;
                }
            }
            $key = $field['key'];
            $value = $answers[$key] ?? null;
            if ($value === null || $value === '') {
                if ($field['required']) {
                    $errors[] = ['field' => $key, 'message' => 'This field is required.'];
                }
                continue;
            }
            $valid = match ($field['type']) {
                'checkbox' => is_bool($value) && (!$field['required'] || $value),
                'number' => is_int($value) && abs($value) <= 999999999,
                'select' => is_string($value) && in_array($value, $field['options'], true),
                'email' => is_string($value) && strlen($value) <= 191
                && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'date' => is_string($value) && self::isDate($value),
                default => is_string($value) && mb_strlen($value) <= ($field['type'] === 'textarea' ? 4000 : 191)
                && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 0,
            };
            if (!$valid) {
                $errors[] = ['field' => $key, 'message' => 'Enter a valid value for this field.'];
            } else {
                $result[$key] = $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $result;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
