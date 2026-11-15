<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\HumanResources\TimeEntry;
use Domain\Shared\Exception\ValidationException;
use RuntimeException;

/** Monday–Sunday calendar review; elapsed time, without payroll rules or rounding. */
final readonly class TimeReviewWeek
{
    public DateTimeImmutable $startsAt;
    public DateTimeImmutable $endsAt;

    public function __construct(string $date, DateTimeZone $timezone)
    {
        $day = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;
        if ($day === false || $day->format('Y-m-d') !== $date || $date < '1000-01-08' || $date > '9998-12-24') {
            throw new ValidationException([['field' => 'week',
                'message' => 'Choose a valid date in the review week.']]);
        }
        $this->startsAt = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $this->endsAt = $this->startsAt->modify('+7 days');
    }

    /**
     * @param list<TimeEntry> $entries
     * @return array<string,mixed>
     */
    public function summarize(array $entries): array
    {
        $days = [];
        for ($day = $this->startsAt; $day < $this->endsAt; $day = $day->modify('+1 day')) {
            $daily = $this->totals($entries, $day->getTimestamp(), $day->modify('+1 day')->getTimestamp());
            $days[] = ['date' => $day->format('Y-m-d'),
                'pending_seconds' => $daily['pending']['seconds'], 'approved_seconds' => $daily['approved']['seconds'],
                'rejected_seconds' => $daily['rejected']['seconds']];
        }
        $items = [];
        foreach ($entries as $entry) {
            $start = max($entry->work->startsAt->getTimestamp(), $this->startsAt->getTimestamp());
            $end = min($entry->work->endsAt->getTimestamp(), $this->endsAt->getTimestamp());
            if ($end <= $start) {
                throw new RuntimeException('A time review contains an interval outside its week.');
            }
            $items[] = ['id' => $entry->id->value, 'status' => $entry->status, 'version' => $entry->version,
                'position_id' => $entry->positionId?->value, 'position_version' => $entry->positionVersion,
                'starts_at' => $entry->work->startsAt->setTimezone($this->startsAt->getTimezone())->format(DATE_ATOM),
                'ends_at' => $entry->work->endsAt->setTimezone($this->startsAt->getTimezone())->format(DATE_ATOM),
                'week_seconds' => $end - $start,
                'duration_seconds' => $entry->work->endsAt->getTimestamp() - $entry->work->startsAt->getTimestamp()];
        }
        return ['week' => $this->startsAt->format('Y-m-d'),
            'through' => $this->endsAt->modify('-1 day')->format('Y-m-d'),
            'previous' => $this->startsAt->modify('-7 days')->format('Y-m-d'),
            'next' => $this->endsAt->format('Y-m-d'), 'timezone' => $this->startsAt->getTimezone()->getName(),
            'totals' => $this->totals($entries, $this->startsAt->getTimestamp(), $this->endsAt->getTimestamp()),
            'days' => $days, 'items' => $items];
    }
    /**
     * @param list<TimeEntry> $entries
     * @return array{pending:array{seconds:int,entries:int},approved:array{seconds:int,entries:int},rejected:array{seconds:int,entries:int}}
     */
    private function totals(array $entries, int $start, int $end): array
    {
        $totals = ['pending' => ['seconds' => 0, 'entries' => 0],
            'approved' => ['seconds' => 0, 'entries' => 0], 'rejected' => ['seconds' => 0, 'entries' => 0]];
        foreach (['pending', 'approved', 'rejected'] as $status) {
            foreach ($entries as $entry) {
                if ($entry->status !== $status) {
                    continue;
                }
                $seconds = min($end, $entry->work->endsAt->getTimestamp())
                - max($start, $entry->work->startsAt->getTimestamp());
                if ($seconds > 0) {
                    $totals[$status]['seconds'] += $seconds;
                    ++$totals[$status]['entries'];
                }
            }
        }
        return $totals;
    }
}
