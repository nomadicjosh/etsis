<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans_html;

/** Public website inquiries have their own schema; they never become admission applications. */
final readonly class WebsiteFormDefinition
{
    public const array TYPES = ['text', 'textarea', 'email', 'tel', 'url', 'number', 'date',
        'checkbox', 'select', 'radio', 'multi_select'];
    public array $facts;

    public static function starter(): array
    {
        return ['key' => 'contact', 'title' => trans_html('Contact us'), 'intro' => '',
            'submit_label' => trans_html('Send message'),
            'success_message' => trans_html('Thank you. Your message has been received.'),
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => trans_html('Your name'), 'required' => true],
                ['name' => 'email', 'type' => 'email', 'label' => trans_html('Email address'), 'required' => true],
                ['name' => 'message', 'type' => 'textarea', 'label' => trans_html('Message'), 'required' => true],
            ]];
    }

    public function __construct(array $facts)
    {
        if (
            array_diff(array_keys($facts), ['key', 'title', 'intro', 'submit_label', 'success_message',
                'fields', 'department_id', 'newsletter'])
            || !is_string($facts['key'] ?? null) || !self::key($facts['key'])
            || !self::text($facts['title'] ?? null, 120, true)
            || !self::text($facts['intro'] ?? '', 1000)
            || !self::text($facts['submit_label'] ?? null, 80, true)
            || !self::text($facts['success_message'] ?? null, 500, true)
            || !is_array($facts['fields'] ?? null) || !array_is_list($facts['fields'])
            || count($facts['fields']) < 1 || count($facts['fields']) > 40
            || strlen(json_encode($facts, JSON_THROW_ON_ERROR)) > 65536
        ) {
            self::invalid();
        }
        $department = $facts['department_id'] ?? null;
        if ($department !== null) {
            if (!is_string($department)) {
                self::invalid();
            }
            new \Domain\Shared\ValueObject\EntityId($department);
        }
        $fields = [];
        $known = [];
        foreach ($facts['fields'] as $field) {
            if (
                !is_array($field) || array_diff(array_keys($field), ['name', 'type', 'label', 'required',
                    'help', 'options', 'min_length', 'max_length', 'min', 'max', 'show_when'])
                || !is_string($field['name'] ?? null) || !self::key($field['name'])
                || isset($known[$field['name']]) || !in_array($field['type'] ?? null, self::TYPES, true)
                || !self::text($field['label'] ?? null, 120, true) || !is_bool($field['required'] ?? null)
                || !self::text($field['help'] ?? '', 300)
            ) {
                self::invalid();
            }
            $field += ['help' => '', 'options' => [], 'min_length' => null, 'max_length' => null,
                'min' => null, 'max' => null, 'show_when' => null];
            $choice = in_array($field['type'], ['select', 'radio', 'multi_select'], true);
            if (
                !is_array($field['options']) || !array_is_list($field['options'])
                || count($field['options']) > 30 || ($choice ? $field['options'] === [] : $field['options'] !== [])
            ) {
                self::invalid();
            }
            $values = [];
            foreach ($field['options'] as $option) {
                if (
                    !is_array($option) || count($option) !== 2
                    || !self::text($option['value'] ?? null, 120, true)
                    || !self::text($option['label'] ?? null, 120, true)
                    || trim($option['value']) !== $option['value'] || preg_match('/[\r\n\t]/', $option['value'])
                    || in_array($option['value'], $values, true)
                ) {
                    self::invalid();
                }
                $values[] = $option['value'];
            }
            foreach (['min_length', 'max_length'] as $limit) {
                if (
                    $field[$limit] !== null && (!is_int($field[$limit]) || $field[$limit] < 1
                    || $field[$limit] > ($field['type'] === 'textarea' ? 20000 : 4000)
                    || !in_array($field['type'], ['text', 'textarea', 'email', 'tel', 'url'], true))
                ) {
                    self::invalid();
                }
            }
            if (
                $field['min_length'] !== null && $field['max_length'] !== null
                && $field['min_length'] > $field['max_length']
            ) {
                self::invalid();
            }
            foreach (['min', 'max'] as $limit) {
                if ($field[$limit] === null) {
                    continue;
                }
                if (
                    $field['type'] === 'number' ? !is_int($field[$limit]) || abs($field[$limit]) > 999999999
                    : ($field['type'] !== 'date' || !self::date($field[$limit]))
                ) {
                    self::invalid();
                }
            }
            if ($field['min'] !== null && $field['max'] !== null && $field['min'] > $field['max']) {
                self::invalid();
            }
            $condition = $field['show_when'];
            if ($condition !== null) {
                if (
                    !is_array($condition) || count($condition) !== 2 || !is_string($condition['field'] ?? null)
                    || !isset($known[$condition['field']]) || !array_key_exists('value', $condition)
                ) {
                    self::invalid();
                }
                $prior = $known[$condition['field']];
                if (
                    $prior['type'] === 'checkbox' ? !is_bool($condition['value'])
                    : (!is_string($condition['value']) || !self::text($condition['value'], 120, true)
                        || $prior['type'] === 'multi_select'
                        || ($prior['options'] !== [] && !in_array(
                            $condition['value'],
                            array_column($prior['options'], 'value'),
                            true
                        )))
                ) {
                    self::invalid();
                }
            }
            $known[$field['name']] = $field;
            $fields[] = $field;
        }
        $newsletter = $facts['newsletter'] ?? null;
        if ($newsletter !== null) {
            self::newsletter($newsletter, $known);
        }
        $this->facts = ['key' => $facts['key'], 'title' => trim($facts['title']), 'intro' => $facts['intro'] ?? '',
            'submit_label' => trim($facts['submit_label']), 'success_message' => trim($facts['success_message']),
            'department_id' => $department, 'newsletter' => $newsletter, 'fields' => $fields];
    }

    private static function newsletter(mixed $binding, array $fields): void
    {
        if (
            !is_array($binding) || count($binding) !== 5
            || array_diff(array_keys($binding), ['connection_id', 'target_hash', 'email_field', 'name_field',
                'consent_field'])
            || !is_string($binding['connection_id'] ?? null)
            || !is_string($binding['target_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $binding['target_hash']) !== 1
        ) {
            self::invalid();
        }
        new \Domain\Shared\ValueObject\EntityId($binding['connection_id']);
        foreach (['email_field' => 'email', 'consent_field' => 'checkbox', 'name_field' => 'text'] as $key => $type) {
            if ($key === 'name_field' && ($binding[$key] ?? null) === null) {
                continue;
            }
            $field = is_string($binding[$key] ?? null) ? ($fields[$binding[$key]] ?? null) : null;
            if (
                $field === null || $field['type'] !== $type || $field['show_when'] !== null
                || ($key === 'consent_field' && $field['required'])
                || ($key === 'email_field' && !$field['required'])
            ) {
                throw new ValidationException([['field' => 'newsletter', 'message' => trans_html(
                    'Map an unconditional required email and a separate optional newsletter consent checkbox.'
                )]]);
            }
        }
    }

    public function answers(#[\SensitiveParameter] array $answers): array
    {
        if (
            strlen(json_encode($answers, JSON_THROW_ON_ERROR)) > 65536
            || array_diff(array_keys($answers), array_column($this->facts['fields'], 'name'))
        ) {
            throw new ValidationException([['field' => 'answers',
                'message' => trans_html('Use the current website form.')]]);
        }
        $clean = [];
        $errors = [];
        foreach ($this->facts['fields'] as $field) {
            $key = $field['name'];
            $condition = $field['show_when'];
            $visible = $condition === null || (($clean[$condition['field']] ?? null) === $condition['value']);
            if (!$visible) {
                if (array_key_exists($key, $answers)) {
                    $errors[] = ['field' => $key,
                        'message' => trans_html('Refresh the form before answering this question.')];
                }
                continue;
            }
            $value = $answers[$key] ?? null;
            if (
                $value === null || $value === '' || $value === [] || ($field['type'] === 'checkbox'
                && $value === false)
            ) {
                if ($field['required']) {
                    $errors[] = ['field' => $key, 'message' => trans_html('This field is required.')];
                } elseif ($field['type'] === 'checkbox') {
                    $clean[$key] = false;
                }
                continue;
            }
            $valid = match ($field['type']) {
                'checkbox' => is_bool($value),
                'multi_select' => is_array($value) && array_is_list($value) && count($value) <= 30
                && count(array_filter($value, 'is_string')) === count($value)
                && count($value) === count(array_unique($value, SORT_REGULAR))
                && array_diff($value, array_column($field['options'], 'value')) === [],
                default => is_string($value) && self::text($value, $field['type'] === 'textarea' ? 20000 : 4000, true),
            };
            if ($valid && is_string($value)) {
                $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
                $length = mb_strlen($value);
                $valid = ($field['min_length'] === null || $length >= $field['min_length'])
                && ($field['max_length'] === null || $length <= $field['max_length'])
                && match ($field['type']) {
                    'email' => strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'url' => filter_var($value, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
                    'number' => preg_match('/^-?(0|[1-9][0-9]{0,8})$/D', $value) === 1,
                    'date' => self::date($value),
                    'select', 'radio' => in_array($value, array_column($field['options'], 'value'), true),
                    default => true,
                };
                if ($valid && in_array($field['type'], ['date', 'number'], true)) {
                    $comparison = $field['type'] === 'number' ? (int) $value : $value;
                    $valid = ($field['min'] === null || $comparison >= $field['min'])
                    && ($field['max'] === null || $comparison <= $field['max']);
                }
            }
            if (!$valid) {
                $errors[] = ['field' => $key, 'message' => trans_html('Enter a valid answer within the stated limits.')];
            } else {
                $clean[$key] = $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    private static function key(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $value) === 1;
    }

    private static function text(mixed $value, int $maximum, bool $required = false): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $maximum
        && (!$required || trim($value) !== '') && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value);
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'definition', 'message' => trans_html(
            'Use a named website form with 1–40 unique labelled fields, valid limits and earlier-field conditions.'
        )]]);
    }
}
