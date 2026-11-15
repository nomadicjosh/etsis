<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeZone;
use Domain\Scheduling\CalendarPeriod;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class CalendarPeriodTest extends TestCase
{
    public function testMonthsHaveCivilBoundariesAcrossLeapYearsAndYearChanges(): void
    {
        foreach (
            [
            ['2028-02-29', '2028-02-01', '2028-02-29', '2028-01-01', '2028-03-01', 29],
            ['2027-02-28', '2027-02-01', '2027-02-28', '2027-01-01', '2027-03-01', 28],
            ['2026-12-31', '2026-12-01', '2026-12-31', '2026-11-01', '2027-01-01', 31],
            ['2027-01-31', '2027-01-01', '2027-01-31', '2026-12-01', '2027-02-01', 31],
            ['1000-01-01', '1000-01-01', '1000-01-31', '0999-12-01', '1000-02-01', 31],
            ['9998-12-31', '9998-12-01', '9998-12-31', '9998-11-01', '9999-01-01', 31],
            ] as [$date, $from, $through, $previous, $next, $length]
        ) {
            $result = new CalendarPeriod($date, new DateTimeZone('UTC'), 'month')->summarize([]);
            self::assertSame('month', $result['view']);
            self::assertSame($from, $result['from']);
            self::assertSame($through, $result['through']);
            self::assertSame($previous, $result['previous']);
            self::assertSame($next, $result['next']);
            self::assertCount($length, $result['days']);
            self::assertSame(['approved' => 0, 'requested' => 0], $result['totals']);
        }
    }

    public function testMonthBoundariesUseSchoolTimeWithShortAndLongClockChangeDays(): void
    {
        $zone = new DateTimeZone('America/Los_Angeles');
        $spring = new CalendarPeriod('2026-03-31', $zone, 'month');
        self::assertSame('2026-03-01T00:00:00-08:00', $spring->startsAt->format(DATE_ATOM));
        self::assertSame('2026-04-01T00:00:00-07:00', $spring->endsAt->format(DATE_ATOM));
        self::assertSame(743 * 3600, $spring->endsAt->getTimestamp() - $spring->startsAt->getTimestamp());
        $fall = new CalendarPeriod('2026-11-30', $zone, 'month');
        self::assertSame(721 * 3600, $fall->endsAt->getTimestamp() - $fall->startsAt->getTimestamp());
    }

    public function testMidnightChangesDoNotShiftThroughDateOrOtherDays(): void
    {
        // Amman moved midnight to 01:00 on April 1, 2011, the exclusive March boundary.
        $month = new CalendarPeriod('2011-03-31', new DateTimeZone('Asia/Amman'), 'month');
        self::assertSame('2011-04-01T01:00:00+03:00', $month->endsAt->format(DATE_ATOM));
        $result = $month->summarize([]);
        self::assertSame('2011-03-31', $result['through']);
        self::assertSame('2011-04-01', $result['next']);
        self::assertSame('2011-03-02', $result['days'][1]['date']);
    }

    public function testMissingCivilDateRejectsTheWholeMonth(): void
    {
        $this->expectException(ValidationException::class);
        new CalendarPeriod('2011-12-01', new DateTimeZone('Pacific/Apia'), 'month');
    }

    public function testInvalidViewsAndDatesAreActionable(): void
    {
        foreach (
            [['2026-02-29', 'month', 'date'], ['2026-03-01', 'year', 'view'],
            ['2026-03-01', '', 'view'], ['9999-01-01', 'month', 'date']] as [$date, $view, $field]
        ) {
            try {
                new CalendarPeriod($date, new DateTimeZone('UTC'), $view);
                self::fail('An invalid calendar period was accepted.');
            } catch (ValidationException $error) {
                self::assertSame($field, $error->errors[0]['field']);
            }
        }
    }

    public function testSpanningMeetingsCountOnceInMonthTotalsAndOnlyReferencesRepeatPerDay(): void
    {
        $result = new CalendarPeriod('2026-08-31', new DateTimeZone('UTC'), 'month')->summarize([
            ['id' => 'spanning', 'status' => 'approved',
                'starts_at' => '2026-07-31T23:00:00Z', 'ends_at' => '2026-09-01T01:00:00Z'],
        ]);
        self::assertCount(1, $result['items']);
        self::assertSame(['approved' => 1, 'requested' => 0], $result['totals']);
        foreach ($result['days'] as $day) {
            self::assertSame(
                [['id' => 'spanning', 'continues_before' => true, 'continues_after' => true]],
                $day['items']
            );
            self::assertSame(['approved' => 1, 'requested' => 0], $day['counts']);
        }
    }

    public function testPublishedAcademicTimesReuseCivilDayGroupingWithoutBookingStatuses(): void
    {
        $period = new CalendarPeriod('2026-03-08', new DateTimeZone('America/Los_Angeles'));
        $result = $period->summarize([['id' => 'published', 'status' => 'published',
            'starts_at' => '2026-03-08T09:30:00Z', 'ends_at' => '2026-03-08T10:30:00Z']], ['published']);
        self::assertSame(['published' => 1], $result['totals']);
        self::assertSame(['published' => 1], $result['days'][6]['counts']);
        self::assertSame('2026-03-08T01:30:00-08:00', $result['items'][0]['starts_at']);
        self::assertSame('2026-03-08T03:30:00-07:00', $result['items'][0]['ends_at']);
    }
}
