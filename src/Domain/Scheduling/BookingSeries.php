<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BookingSeries
{
    /** Expand civil dates separately from timezone arithmetic so skipped/ambiguous times cannot shift meetings.
     * @return list<TimeSlot>
     */
    public function pattern(BookingPattern $pattern): array
    {
        $slots = [];
        for (
            $day = BookingPattern::day($pattern->from, 'from'); $day->format('Y-m-d') <= $pattern->through;
            $day = $day->modify('+1 day')
        ) {
            $date = $day->format('Y-m-d');
            if (!$pattern->meetsOn($date) || in_array($date, $pattern->excludedDates, true)) {
                continue;
            }
            $endDate = $pattern->overnight ? $day->modify('+1 day')->format('Y-m-d') : $date;
            $slots[] = new TimeSlot(
                $this->local($date, $pattern->startsAt, $pattern->timezone, 'start_time'),
                $this->local($endDate, $pattern->endsAt, $pattern->timezone, 'end_time')
            );
            if (count($slots) > 366) {
                BookingPattern::invalid('through', 'Use at most 366 explicit occurrences.');
            }
        }
        if ($slots === []) {
            BookingPattern::invalid('weekdays', 'The pattern must include at least one meeting.');
        }
        return $slots;
    }

    private function local(string $date, string $time, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $text = $date . ' ' . $time;
        $wall = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        $stamp = $wall->getTimestamp();
        $offsets = [$zone->getOffset($wall)];
        foreach ($zone->getTransitions($stamp - 172800, $stamp + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = new DateTimeImmutable('@' . ($stamp - $offset))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $text) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            BookingPattern::invalid($field, $text . ' in ' . $zone->getName()
                . (count($matches) === 0
                    ? ' does not exist after a clock change.' : ' occurs twice after a clock change.')
                . ' Change the time or skip this meeting date.');
        }
        return array_values($matches)[0];
    }

    /** @return list<TimeSlot> */
    public function occurrences(TimeSlot $first, DateTimeImmutable $lastStartsOn, int $repeatEveryDays): array
    {
        if ($repeatEveryDays < 1 || $repeatEveryDays > 366 || $lastStartsOn < $first->startsAt) {
            throw new InvalidArgumentException('A positive recurrence and finite end date are required.');
        }
        $slots = [];
        $start = $first->startsAt;
        $end = $first->endsAt;
        while ($start <= $lastStartsOn) {
            if (count($slots) >= 366) {
                throw new InvalidArgumentException('A booking series cannot exceed 366 occurrences.');
            }
            $slots[] = new TimeSlot($start, $end);
            // Calendar-day arithmetic preserves local class times across daylight-saving changes.
            $start = $start->modify(sprintf('+%d days', $repeatEveryDays));
            $end = $end->modify(sprintf('+%d days', $repeatEveryDays));
        }

        return $slots;
    }
}
