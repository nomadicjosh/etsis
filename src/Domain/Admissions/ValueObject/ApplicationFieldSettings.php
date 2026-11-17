<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use InvalidArgumentException;

final readonly class ApplicationFieldSettings
{
    /** @param array<string,mixed> $values */
    public function __construct(private string $type, public array $values)
    {
        $allowed = match ($type) {
            'text', 'textarea', 'email' => ['placeholder', 'min_length', 'max_length'],
            'number' => ['min_value', 'max_value'],
            'date' => ['earliest_date', 'latest_date'],
            default => [],
        };
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException('These settings do not apply to this question type.');
        }
        foreach ($values as $key => $value) {
            $valid = match ($key) {
                'placeholder' => is_string($value) && mb_strlen($value) <= 160
                && preg_match('/[\x00-\x1f\x7f]/', $value) === 0,
                'min_length', 'max_length' => is_int($value) && $value >= 0
                && $value <= ($type === 'textarea' ? 4000 : 191),
                'min_value', 'max_value' => is_int($value) && abs($value) <= 999999999,
                'earliest_date', 'latest_date' => is_string($value) && self::isDate($value),
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('A question setting is invalid or exceeds the supported limits.');
            }
        }
        if (
            ($values['min_length'] ?? 0) > ($values['max_length'] ?? ($type === 'textarea' ? 4000 : 191))
            || ($values['min_value'] ?? -999999999) > ($values['max_value'] ?? 999999999)
            || ($values['earliest_date'] ?? '0001-01-01') > ($values['latest_date'] ?? '9999-12-31')
        ) {
            throw new InvalidArgumentException('A question minimum cannot exceed its maximum.');
        }
    }

    public function violation(string|int|bool $value): ?string
    {
        foreach ($this->values as $key => $bound) {
            $message = match ($key) {
                'min_length' => mb_strlen((string) $value) < $bound ? "Use at least {$bound} characters." : null,
                'max_length' => mb_strlen((string) $value) > $bound ? "Use no more than {$bound} characters." : null,
                'min_value' => $value < $bound ? "Enter {$bound} or more." : null,
                'max_value' => $value > $bound ? "Enter {$bound} or less." : null,
                'earliest_date' => $value < $bound ? "Choose a date on or after {$bound}." : null,
                'latest_date' => $value > $bound ? "Choose a date on or before {$bound}." : null,
                default => null,
            };
            if ($message !== null) {
                return $message;
            }
        }
        return null;
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 || substr($value, 0, 4) === '0000') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
