<?php

declare(strict_types=1);

namespace Domain\Shared\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;

/** A civil minute must identify exactly one instant, including across clock changes. */
final class LocalDateTime
{
    public static function resolve(string $text, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $wall = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $text) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone('UTC')) : false;
        if (
            $wall === false || $wall->format('Y-m-d\TH:i') !== $text
            || $text < '1000-01-02T00:00' || $text > '9998-12-30T23:59'
        ) {
            throw new ValidationException([['field' => $field, 'message' => 'Enter a valid local date and time.']]);
        }
        $stamp = $wall->getTimestamp();
        $offsets = [$zone->getOffset($wall)];
        foreach ($zone->getTransitions($stamp - 172800, $stamp + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = new DateTimeImmutable('@' . ($stamp - $offset))->setTimezone($zone);
            if ($candidate->format('Y-m-d\TH:i') === $text) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new ValidationException([['field' => $field, 'message' => $text . ' in ' . $zone->getName()
                . (count($matches) === 0 ? ' does not exist after a clock change.'
                    : ' occurs twice after a clock change.') . ' Choose another local time.']]);
        }
        return array_values($matches)[0];
    }
}
