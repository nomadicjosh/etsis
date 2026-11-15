<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;

/** A bounded booking calendar read; it makes no reservation or approval decision. */
final readonly class BookingTimetable
{
    public DateTimeImmutable $startsAt;
    public DateTimeImmutable $endsAt;
    /** @var list<TimeSlot> */
    private array $days;

    public function __construct(string $date, DateTimeZone $timezone)
    {
        $day = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
        if (
            $day === false || $day->format('Y-m-d') !== $date
            || $date < '1000-01-06' || $date > '9998-12-27'
        ) {
            throw new ValidationException([['field' => 'week',
                'message' => 'Choose a valid date in the timetable week.']]);
        }
        $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $boundaries = [];
        // A timezone may skip an entire civil date. Do not silently relabel its day cards.
        for ($i = 0; $i <= 7; ++$i) {
            $expected = $monday->modify('+' . $i . ' days')->format('Y-m-d');
            $boundary = new DateTimeImmutable($expected, $timezone);
            if ($boundary->format('Y-m-d') !== $expected) {
                throw new ValidationException([['field' => 'week',
                    'message' => 'This timezone skips a date in this week. Use the room agenda for these dates.']]);
            }
            $boundaries[] = $boundary;
        }
        $this->startsAt = $boundaries[0];
        $this->endsAt = $boundaries[7];
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $days[] = new TimeSlot($boundaries[$i], $boundaries[$i + 1]);
        }
        $this->days = $days;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function summarize(array $rows): array
    {
        $days = [];
        $totals = ['approved' => 0, 'requested' => 0];
        $timezone = $this->startsAt->getTimezone();
        foreach ($rows as &$row) {
            $row['starts_at'] = new DateTimeImmutable($row['starts_at'])->setTimezone($timezone)->format(DATE_ATOM);
            $row['ends_at'] = new DateTimeImmutable($row['ends_at'])->setTimezone($timezone)->format(DATE_ATOM);
            ++$totals[$row['status']];
        }
        unset($row);
        foreach ($this->days as $interval) {
            $day = $interval->startsAt;
            $end = $interval->endsAt;
            $items = [];
            $counts = ['approved' => 0, 'requested' => 0];
            foreach ($rows as $row) {
                $startAt = new DateTimeImmutable($row['starts_at']);
                $endAt = new DateTimeImmutable($row['ends_at']);
                if ($startAt < $end && $endAt > $day) {
                    ++$counts[$row['status']];
                    $items[] = ['id' => $row['id'],
                        'continues_before' => $startAt < $day, 'continues_after' => $endAt > $end];
                }
            }
            $days[] = ['date' => $day->format('Y-m-d'), 'counts' => $counts, 'items' => $items];
        }
        return ['week' => $this->startsAt->format('Y-m-d'),
            'through' => $this->endsAt->modify('-1 day')->format('Y-m-d'),
            'previous' => $this->startsAt->modify('-7 days')->format('Y-m-d'),
            'next' => $this->endsAt->format('Y-m-d'), 'timezone' => $this->startsAt->getTimezone()->getName(),
            'totals' => $totals, 'days' => $days, 'items' => $rows];
    }
}
