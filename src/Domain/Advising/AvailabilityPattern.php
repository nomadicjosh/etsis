<?php

declare(strict_types=1);

namespace Domain\Advising;

use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\BookingSeries;
use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

/** Finite authoring pattern; accepted offers retain every explicit dated window. */
final readonly class AvailabilityPattern
{
    public BookingPattern $pattern;
    public function __construct(array $data)
    {
        $keys = ['from', 'through', 'start_time', 'end_time', 'weekdays', 'interval_weeks',
            'excluded_dates', 'timezone'];
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new ValidationException([['field' => 'pattern', 'message' => trans(
                'Review a complete availability pattern.'
            )]]);
        }
        foreach (['from', 'through', 'start_time', 'end_time', 'timezone'] as $key) {
            if (!is_string($data[$key])) {
                throw new ValidationException([['field' => $key, 'message' => trans(
                    'Use the supplied date and time fields.'
                )]]);
            }
        }
        if (!is_array($data['weekdays']) || !is_array($data['excluded_dates']) || !is_int($data['interval_weeks'])) {
            throw new ValidationException([['field' => 'pattern', 'message' => trans(
                'Choose valid weekdays, frequency and skipped dates.'
            )]]);
        }
        $this->pattern = new BookingPattern(
            $data['from'],
            $data['through'],
            $data['start_time'],
            $data['end_time'],
            $data['weekdays'],
            $data['interval_weeks'],
            $data['excluded_dates'],
            false,
            $data['timezone']
        );
    }

    public function facts(): array
    {
        $p = $this->pattern;
        return ['from' => $p->from, 'through' => $p->through, 'start_time' => $p->startsAt, 'end_time' => $p->endsAt,
            'weekdays' => $p->weekdays, 'interval_weeks' => $p->intervalWeeks, 'excluded_dates' => $p->excludedDates,
            'timezone' => $p->timezone->getName()];
    }

    /** @return list<AppointmentTime> */
    public function times(int $minutes): array
    {
        $result = [];
        foreach (new BookingSeries()->pattern($this->pattern) as $slot) {
            $time = new AppointmentTime(
                $slot->startsAt->format('Y-m-d\TH:i'),
                $slot->endsAt->format('Y-m-d\TH:i'),
                $this->pattern->timezone->getName()
            );
            AdvisingAvailability::times($time, $minutes);
            $result[] = $time;
            if (count($result) > 20) {
                throw new ValidationException([['field' => 'through', 'message' => trans(
                    'Offer at most twenty dated windows together. Shorten the pattern.'
                )]]);
            }
        }
        return $result;
    }
}
