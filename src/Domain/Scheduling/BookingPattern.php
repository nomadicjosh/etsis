<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;
use Throwable;

/** A finite authoring pattern; accepted bookings retain the generated explicit instants. */
final readonly class BookingPattern
{
    /** @var list<int> */
    public array $weekdays;
    /** @var list<string> */
    public array $excludedDates;
    public DateTimeZone $timezone;

    /** @param array<mixed> $weekdays
     * @param array<mixed> $excludedDates
     */
    public function __construct(
        public string $from,
        public string $through,
        public string $startsAt,
        public string $endsAt,
        array $weekdays,
        public int $intervalWeeks,
        array $excludedDates,
        public bool $overnight,
        string $timezone,
    ) {
        $first = self::day($from, 'from');
        $last = self::day($through, 'through');
        if ($last < $first || (int) $first->diff($last)->format('%a') > 365) {
            self::invalid('through', 'Choose an inclusive date range of 366 days or fewer.');
        }
        foreach (['start_time' => $startsAt, 'end_time' => $endsAt] as $field => $time) {
            if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
                self::invalid($field, 'Enter a valid local time.');
            }
        }
        if ((!$overnight && $endsAt <= $startsAt) || ($overnight && $endsAt > $startsAt)) {
            self::invalid('end_time', $overnight
                ? 'For an overnight meeting, use an end time no later than the start time.'
                : 'Choose an end after the start, or select Ends next day.');
        }
        if (
            !array_is_list($weekdays) || count($weekdays) < 1 || count($weekdays) > 7
            || count(array_unique($weekdays, SORT_REGULAR)) !== count($weekdays)
        ) {
            self::invalid('weekdays', 'Choose at least one meeting day, without duplicates.');
        }
        foreach ($weekdays as $weekday) {
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
                self::invalid('weekdays', 'Choose valid meeting days.');
            }
        }
        sort($weekdays);
        $this->weekdays = $weekdays;
        if ($intervalWeeks < 1 || $intervalWeeks > 12) {
            self::invalid('interval_weeks', 'Repeat every 1 to 12 weeks.');
        }
        if (!array_is_list($excludedDates) || count($excludedDates) > 100) {
            self::invalid('excluded_dates', 'Skip at most 100 explicit meeting dates.');
        }
        foreach ($excludedDates as $date) {
            if (!is_string($date)) {
                self::invalid('excluded_dates', 'Use ISO calendar dates for closures.');
            }
            self::day($date, 'excluded_dates');
            if ($date < $from || $date > $through || !$this->meetsOn($date)) {
                self::invalid('excluded_dates', 'Each skipped date must be a meeting date within the selected range.');
            }
        }
        if (count(array_unique($excludedDates)) !== count($excludedDates)) {
            self::invalid('excluded_dates', 'List each skipped date only once.');
        }
        sort($excludedDates);
        $this->excludedDates = $excludedDates;
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Throwable) {
            self::invalid('timezone', 'Use the institution timezone supplied by the planner.');
        }
    }

    public function meetsOn(string $date): bool
    {
        $first = self::day($this->from, 'from');
        $day = self::day($date, 'from');
        $monday = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $week = intdiv((int) $monday->diff($day)->format('%a'), 7);
        return in_array((int) $day->format('N'), $this->weekdays, true) && $week % $this->intervalWeeks === 0;
    }

    public static function day(string $value, string $field): DateTimeImmutable
    {
        $day = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($day === false || $day->format('Y-m-d') !== $value || $value < '1000-01-02' || $value > '9998-12-30') {
            self::invalid($field, 'Enter a valid ISO calendar date.');
        }
        return $day;
    }
    public static function invalid(string $field, string $message): never
    {
        throw new ValidationException([['field' => $field, 'message' => $message]]);
    }
}
