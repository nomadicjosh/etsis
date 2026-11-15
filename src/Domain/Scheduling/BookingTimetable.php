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

    public function __construct(string $date, DateTimeZone $timezone, public string $view = 'week')
    {
        if (!in_array($view, ['week', 'month'], true)) {
            throw new ValidationException([['field' => 'view', 'message' => 'Choose week or month.']]);
        }
        $day = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
        if (
            $day === false || $day->format('Y-m-d') !== $date
            || $date < ($view === 'month' ? '1000-01-01' : '1000-01-06')
            || $date > ($view === 'month' ? '9998-12-31' : '9998-12-27')
        ) {
            throw new ValidationException([['field' => 'date',
                'message' => 'Choose a valid date in the timetable.']]);
        }
        $first = $view === 'month' ? $day->modify('first day of this month')
        : $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $length = $view === 'month' ? (int) $first->format('t') : 7;
        $boundaries = [];
        // A timezone may skip an entire civil date. Do not silently relabel its day cards.
        for ($i = 0; $i <= $length; ++$i) {
            $expected = $first->modify('+' . $i . ' days')->format('Y-m-d');
            $boundary = new DateTimeImmutable($expected, $timezone);
            if ($boundary->format('Y-m-d') !== $expected) {
                throw new ValidationException([['field' => 'date',
                    'message' => 'This timezone skips a date in this period. Use the room agenda for these dates.']]);
            }
            $boundaries[] = $boundary;
        }
        $this->startsAt = $boundaries[0];
        $this->endsAt = $boundaries[$length];
        $days = [];
        for ($i = 0; $i < $length; ++$i) {
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
        $intervals = [];
        foreach ($rows as &$row) {
            $start = new DateTimeImmutable($row['starts_at'])->setTimezone($timezone);
            $end = new DateTimeImmutable($row['ends_at'])->setTimezone($timezone);
            $intervals[$row['id']] = [$start, $end];
            $row['starts_at'] = $start->format(DATE_ATOM);
            $row['ends_at'] = $end->format(DATE_ATOM);
            ++$totals[$row['status']];
        }
        unset($row);
        foreach ($this->days as $interval) {
            $day = $interval->startsAt;
            $end = $interval->endsAt;
            $items = [];
            $counts = ['approved' => 0, 'requested' => 0];
            foreach ($rows as $row) {
                [$startAt, $endAt] = $intervals[$row['id']];
                if ($startAt < $end && $endAt > $day) {
                    ++$counts[$row['status']];
                    $items[] = ['id' => $row['id'],
                        'continues_before' => $startAt < $day, 'continues_after' => $endAt > $end];
                }
            }
            $days[] = ['date' => $day->format('Y-m-d'), 'counts' => $counts, 'items' => $items];
        }
        // Navigate in UTC civil dates: local midnight shifts must not shift later dates or months.
        $first = new DateTimeImmutable($this->startsAt->format('Y-m-d'), new DateTimeZone('UTC'));
        return ['from' => $first->format('Y-m-d'), 'view' => $this->view,
            'through' => $first->modify('+' . (count($this->days) - 1) . ' days')->format('Y-m-d'),
            'previous' => $first->modify($this->view === 'month' ? '-1 month' : '-7 days')->format('Y-m-d'),
            'next' => $this->endsAt->format('Y-m-d'), 'timezone' => $this->startsAt->getTimezone()->getName(),
            'totals' => $totals, 'days' => $days, 'items' => $rows];
    }
}
