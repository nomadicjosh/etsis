<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeZone;
use Domain\Scheduling\CalendarPeriod;
use Domain\Scheduling\TimetableOverlapReview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimetableOverlapReviewTest extends TestCase
{
    /** @return array<string,mixed> */
    private function meeting(string $id, string $section, string $start, string $end): array
    {
        return ['id' => $id, 'section_id' => $section, 'starts_at' => $start, 'ends_at' => $end];
    }

    private function period(string $date = '2026-08-10', string $view = 'week'): CalendarPeriod
    {
        return new CalendarPeriod($date, new DateTimeZone('America/Los_Angeles'), $view);
    }

    public function testEmptyReviewKeepsCompleteCivilDaysAndZeroCounts(): void
    {
        $review = new TimetableOverlapReview($this->period(), []);
        self::assertSame(['pairs' => 0, 'meetings' => 0, 'sections' => 0], $review->summary);
        self::assertSame([], $review->items);
        self::assertCount(7, $review->days);
        self::assertSame([], $review->days['2026-08-10']['counts']);
    }

    public function testNestedCrossSectionMeetingsCountOnceAndRetainInputOrder(): void
    {
        $items = [
            $this->meeting('nested', 'B', '2026-08-10T10:30:00-07:00', '2026-08-10T10:45:00-07:00'),
            $this->meeting('enclosing', 'A', '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00'),
            $this->meeting('touching', 'C', '2026-08-10T11:00:00-07:00', '2026-08-10T12:00:00-07:00'),
        ];
        $review = new TimetableOverlapReview($this->period(), $items);
        self::assertSame(['pairs' => 1, 'meetings' => 2, 'sections' => 2], $review->summary);
        self::assertSame(['nested', 'enclosing', 'touching'], array_column($review->items, 'id'));
        self::assertSame([1, 1, 0], array_column($review->items, 'overlap_count'));
        self::assertSame($review->summary, $review->days['2026-08-10']['summary']);
        self::assertArrayNotHasKey('overlap_count', $items[0]);
    }

    public function testBackToBackAndSameSectionHybridMeetingsAreNotFlagged(): void
    {
        $review = new TimetableOverlapReview($this->period(), [
            $this->meeting('room', 'A', '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00'),
            $this->meeting('online', 'A', '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00'),
            $this->meeting('next', 'B', '2026-08-10T11:00:00-07:00', '2026-08-10T12:00:00-07:00'),
        ]);
        self::assertSame(0, $review->summary['pairs']);
        self::assertSame([0, 0, 0], array_column($review->items, 'overlap_count'));
    }

    public function testOneMeetingMayOverlapSeveralDistinctMeetingsWithoutBuildingAPairGraph(): void
    {
        $review = new TimetableOverlapReview($this->period(), [
            $this->meeting('room', 'A', '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00'),
            $this->meeting('online', 'A', '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00'),
            $this->meeting('other', 'B', '2026-08-10T10:30:00-07:00', '2026-08-10T11:00:00-07:00'),
        ]);
        self::assertSame(['pairs' => 2, 'meetings' => 3, 'sections' => 2], $review->summary);
        self::assertSame([1, 1, 2], array_column($review->items, 'overlap_count'));
        self::assertArrayNotHasKey('pairs', $review->items[0]);
    }

    public function testAnOvernightMeetingIsNotFlaggedOnADayWhereItsOverlapHasEnded(): void
    {
        $review = new TimetableOverlapReview($this->period(), [
            $this->meeting('overnight', 'A', '2026-08-10T23:00:00-07:00', '2026-08-11T02:00:00-07:00'),
            $this->meeting('monday', 'B', '2026-08-10T22:30:00-07:00', '2026-08-10T23:30:00-07:00'),
        ]);
        self::assertSame(1, $review->summary['pairs']);
        self::assertSame(1, $review->days['2026-08-10']['summary']['pairs']);
        self::assertSame(0, $review->days['2026-08-11']['summary']['pairs']);
        self::assertSame([], $review->days['2026-08-11']['counts']);
    }

    public function testMidnightIsExclusiveAndMultiDayPairsCountOnceInThePeriod(): void
    {
        $review = new TimetableOverlapReview($this->period(), [
            $this->meeting('long', 'A', '2026-08-10T22:00:00-07:00', '2026-08-12T00:00:00-07:00'),
            $this->meeting('also-long', 'B', '2026-08-10T23:00:00-07:00', '2026-08-12T00:00:00-07:00'),
        ]);
        self::assertSame(1, $review->summary['pairs']);
        self::assertSame(1, $review->days['2026-08-10']['summary']['pairs']);
        self::assertSame(1, $review->days['2026-08-11']['summary']['pairs']);
        self::assertSame(0, $review->days['2026-08-12']['summary']['pairs']);
    }

    public function testWindowClippingAndZeroLengthIntersectionsCannotCreateWarnings(): void
    {
        $review = new TimetableOverlapReview($this->period(), [
            $this->meeting('earlier', 'A', '2026-08-09T22:00:00-07:00', '2026-08-10T00:00:00-07:00'),
            $this->meeting('entering', 'B', '2026-08-09T23:00:00-07:00', '2026-08-10T01:00:00-07:00'),
            $this->meeting('inside', 'C', '2026-08-10T00:30:00-07:00', '2026-08-10T01:30:00-07:00'),
            $this->meeting('later', 'D', '2026-08-17T00:00:00-07:00', '2026-08-17T01:00:00-07:00'),
        ]);
        self::assertSame([0, 1, 1, 0], array_column($review->items, 'overlap_count'));
        self::assertSame(['pairs' => 1, 'meetings' => 2, 'sections' => 2], $review->summary);
    }

    public function testFallClockRepetitionAndSpringChangeUseInstantsInsteadOfWallClockLabels(): void
    {
        $fall = new TimetableOverlapReview($this->period('2026-11-01'), [
            $this->meeting('first-one', 'A', '2026-11-01T01:15:00-07:00', '2026-11-01T01:45:00-07:00'),
            $this->meeting('second-one', 'B', '2026-11-01T01:15:00-08:00', '2026-11-01T01:45:00-08:00'),
        ]);
        self::assertSame(0, $fall->summary['pairs']);
        $spring = new TimetableOverlapReview($this->period('2026-03-08'), [
            $this->meeting('clock-change', 'A', '2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00'),
            $this->meeting('later', 'B', '2026-03-08T03:15:00-07:00', '2026-03-08T04:00:00-07:00'),
        ]);
        self::assertSame(1, $spring->summary['pairs']);
        self::assertSame(1, $spring->days['2026-03-08']['summary']['pairs']);
    }

    public function testMonthReviewKeepsExactDenseCountsWithoutReturningPairLists(): void
    {
        $items = [];
        for ($i = 0; $i < 500; ++$i) {
            $items[] = $this->meeting(
                'meeting-' . $i,
                'section-' . ($i % 50),
                '2026-07-31T00:00:00-07:00',
                '2026-09-01T00:00:00-07:00'
            );
        }
        $review = new TimetableOverlapReview($this->period('2026-08-10', 'month'), $items);
        self::assertSame(['pairs' => 122500, 'meetings' => 500, 'sections' => 50], $review->summary);
        self::assertCount(500, $review->items);
        self::assertSame([490], array_values(array_unique(array_column($review->items, 'overlap_count'))));
        self::assertCount(31, $review->days);
        foreach ($review->days as $day) {
            self::assertSame($review->summary, $day['summary']);
            self::assertCount(500, $day['counts']);
        }
    }

    public function testDuplicateIdentitiesCannotProduceMisleadingCounts(): void
    {
        $meeting = $this->meeting('same', 'A', '2026-08-10T10:00:00Z', '2026-08-10T11:00:00Z');
        $this->expectException(InvalidArgumentException::class);
        new TimetableOverlapReview($this->period(), [$meeting, $meeting]);
    }

    public function testAnExcessiveInputIsRejectedBeforeReview(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimetableOverlapReview($this->period(), array_fill(0, 501, []));
    }
}
