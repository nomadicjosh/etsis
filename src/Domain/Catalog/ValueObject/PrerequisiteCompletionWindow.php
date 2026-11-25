<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

/** Inclusive actual-completion dates, independent of today's catalog, term and grade-recording dates. */
final readonly class PrerequisiteCompletionWindow
{
    public function __construct(public array $definition)
    {
        if (
            count($definition) !== 2 || array_diff(array_keys($definition), ['from', 'through']) !== []
            || !array_key_exists('from', $definition) || !array_key_exists('through', $definition)
            || ($definition['from'] === null && $definition['through'] === null)
        ) {
            throw new InvalidArgumentException(trans('Provide a completion date boundary or clear the window.'));
        }
        foreach ($definition as $date) {
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
