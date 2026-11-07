<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use InvalidArgumentException;

final class BookingSeries
{
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
