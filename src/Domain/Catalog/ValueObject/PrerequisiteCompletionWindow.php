<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

/** Inclusive actual-completion dates, optionally relative to the retained instructional start. */
final readonly class PrerequisiteCompletionWindow
{
    public array $definition;

    public function __construct(array $definition)
    {
        if (
            array_diff(array_keys($definition), ['from', 'through', 'max_age_months']) !== []
            || !array_key_exists('from', $definition) || !array_key_exists('through', $definition)
            || ($definition['from'] === null && $definition['through'] === null
                && !array_key_exists('max_age_months', $definition))
        ) {
            throw new InvalidArgumentException(trans('Provide a completion date boundary or clear the window.'));
        }
        if (
            array_key_exists('max_age_months', $definition)
            && (!is_int($definition['max_age_months']) || $definition['max_age_months'] < 1
                || $definition['max_age_months'] > 1200)
        ) {
            throw new InvalidArgumentException(trans('Use a whole prerequisite age limit from 1 through 1200 months.'));
        }
        foreach ([$definition['from'], $definition['through']] as $date) {
            if ($date !== null && (!is_string($date) || !self::validDate($date))) {
                throw new InvalidArgumentException(trans('Use valid completion dates in YYYY-MM-DD format.'));
            }
        }
        if (
            $definition['from'] !== null && $definition['through'] !== null
            && $definition['from'] > $definition['through']
        ) {
            throw new InvalidArgumentException(trans('Completion from must not be after completion through.'));
        }
        ksort($definition, SORT_STRING);
        $this->definition = $definition;
    }

    /** Month subtraction clamps the day rather than overflowing into the following month. */
    public function earliestCompletion(string $startsOn): ?string
    {
        if (!self::validDate($startsOn)) {
            throw new InvalidArgumentException(trans('Use a valid published instructional start date.'));
        }
        $months = $this->definition['max_age_months'] ?? null;
        if ($months === null) {
            return null;
        }
        $index = (int) substr($startsOn, 0, 4) * 12 + (int) substr($startsOn, 5, 2) - 1 - $months;
        if ($index < 12) {
            return '0001-01-01';
        }
        $first = sprintf('%04d-%02d-01', intdiv($index, 12), ($index % 12) + 1);
        $date = new DateTimeImmutable($first, new DateTimeZone('UTC'));
        return substr($first, 0, 8) . str_pad(
            (string) min((int) substr($startsOn, 8, 2), (int) $date->format('t')),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }

    public function accepts(?string $completedOn, ?string $startsOn = null): bool
    {
        if ($completedOn === null || !self::validDate($completedOn)) {
            return false;
        }
        $window = $this->definition;
        if (
            ($window['from'] !== null && $completedOn < $window['from'])
            || ($window['through'] !== null && $completedOn > $window['through'])
        ) {
            return false;
        }
        return !isset($window['max_age_months']) || ($startsOn !== null
            && $completedOn >= $this->earliestCompletion($startsOn) && $completedOn <= $startsOn);
    }

    public static function validDate(string $date): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1 || $date < '0001-01-01') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
