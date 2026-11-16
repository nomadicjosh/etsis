<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use Domain\Shared\ValueObject\LocalDateTime;
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
                LocalDateTime::resolve($date . 'T' . $pattern->startsAt, $pattern->timezone, 'start_time'),
                LocalDateTime::resolve($endDate . 'T' . $pattern->endsAt, $pattern->timezone, 'end_time')
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
