<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\Room;
use Domain\Scheduling\RoomTimetable;
use Domain\Scheduling\Service\RoomService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RoomTimetableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $f, ?RoomRepository $rooms = null): RoomService
    {
        return new RoomService(
            $rooms ?? new SqlRoomRepository($f->sql),
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlBuildingRepository($f->sql)
        );
    }

    private function room(SisFixture $f): EntityId
    {
        return $f->room(EntityId::generate()->value, 20);
    }

    private function booking(
        SisFixture $f,
        EntityId $room,
        string $start,
        string $end,
        string $status = 'requested'
    ): Booking {
        $booking = new Booking(
            EntityId::generate(),
            $room,
            $f->access->actor,
            'Fictional meeting',
            [BookingOccurrence::plan(new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end)))],
            $status
        );
        new SqlBookingRepository($f->sql)->add($booking);
        return $booking;
    }

    public function testSchoolCalendarWeekUsesActualDstBoundariesAndRejectsSkippedCivilDates(): void
    {
        $zone = new DateTimeZone('America/Los_Angeles');
        $spring = new RoomTimetable('2026-03-08', $zone);
        self::assertSame('2026-03-02T00:00:00-08:00', $spring->startsAt->format(DATE_ATOM));
        self::assertSame('2026-03-09T00:00:00-07:00', $spring->endsAt->format(DATE_ATOM));
        self::assertSame(167 * 3600, $spring->endsAt->getTimestamp() - $spring->startsAt->getTimestamp());
        $fall = new RoomTimetable('2026-11-01', $zone);
        self::assertSame(169 * 3600, $fall->endsAt->getTimestamp() - $fall->startsAt->getTimestamp());
        self::assertCount(7, $fall->summarize([])['days']);
        foreach (['1000-01-06', '9998-12-27'] as $boundary) {
            $calendar = new RoomTimetable($boundary, $zone);
            self::assertSame(
                $calendar->startsAt->format('Y-m-d'),
                new RoomTimetable($calendar->summarize([])['week'], $zone)->startsAt->format('Y-m-d')
            );
        }
        $this->expectException(ValidationException::class);
        new RoomTimetable('2011-12-30', new DateTimeZone('Pacific/Apia'));
    }

    public function testWeekUsesHalfOpenBoundariesOmitsCancelledAndOtherRoomsAndDoesNotWrite(): void
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2026-03-08T18:00:00', new DateTimeZone('America/Los_Angeles'));
        $room = $this->room($f);
        $beforeWeek = $this->booking($f, $room, '2026-03-02T07:00:00Z', '2026-03-02T08:00:00Z', 'approved');
        $overnight = $this->booking($f, $room, '2026-03-02T07:30:00Z', '2026-03-03T09:00:00Z', 'approved');
        $late = $this->booking($f, $room, '2026-03-09T06:00:00Z', '2026-03-09T08:00:00Z');
        $afterWeek = $this->booking($f, $room, '2026-03-09T07:00:00Z', '2026-03-09T08:00:00Z');
        $this->booking($f, $room, '2026-03-04T12:00:00Z', '2026-03-04T13:00:00Z', 'cancelled');
        $this->booking($f, $this->room($f), '2026-03-04T12:00:00Z', '2026-03-04T13:00:00Z');
        $before = [];
        foreach (['sis_bookings', 'sis_booking_slots', 'sis_audit', 'sis_history_events'] as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $result = $this->service($f)->week($room);
        self::assertSame('2026-03-02', $result['week']);
        self::assertSame('2026-03-08', $result['through']);
        self::assertSame('2026-02-23', $result['previous']);
        self::assertSame('2026-03-09', $result['next']);
        self::assertSame('America/Los_Angeles', $result['timezone']);
        self::assertSame(['approved' => 1, 'requested' => 1], $result['totals']);
        self::assertSame([$overnight->id->value, $late->id->value], array_column($result['items'], 'booking_id'));
        self::assertNotContains($beforeWeek->id->value, array_column($result['items'], 'booking_id'));
        self::assertNotContains($afterWeek->id->value, array_column($result['items'], 'booking_id'));
        self::assertTrue($result['days'][0]['items'][0]['continues_before']);
        self::assertTrue($result['days'][0]['items'][0]['continues_after']);
        self::assertTrue($result['days'][1]['items'][0]['continues_before']);
        self::assertFalse($result['days'][1]['items'][0]['continues_after']);
        self::assertTrue($result['days'][6]['items'][0]['continues_after']);
        self::assertSame('2026-03-08T23:00:00-07:00', $result['items'][1]['starts_at']);
        self::assertArrayNotHasKey('owner_user_id', $result['items'][0]);
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
    }

    public function testAnOccurrenceEndingAtMidnightDoesNotAppearOnTheFollowingDay(): void
    {
        $f = new SisFixture();
        $room = $this->room($f);
        $this->booking($f, $room, '2026-08-10T23:00:00-07:00', '2026-08-11T00:00:00-07:00', 'approved');
        $result = $this->service($f)->week($room, '2026-08-11');
        self::assertCount(1, $result['days'][0]['items']);
        self::assertFalse($result['days'][0]['items'][0]['continues_after']);
        self::assertSame([], $result['days'][1]['items']);
    }

    public function testAMidnightClockChangeDoesNotMoveLaterDayBoundaries(): void
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2010-03-23T12:00:00', new DateTimeZone('Asia/Tehran'));
        $room = $this->room($f);
        $this->booking($f, $room, '2010-03-23T00:15:00+04:30', '2010-03-23T00:45:00+04:30');
        $result = $this->service($f)->week($room);
        self::assertSame('2010-03-22', $result['week']);
        self::assertSame([], $result['days'][0]['items']);
        self::assertCount(1, $result['days'][1]['items']);
    }

    public function testFiveHundredMeetingsAreCompleteAndAnOverflowNeverReturnsPartialTotals(): void
    {
        $f = new SisFixture();
        $room = $this->room($f);
        $slots = [];
        $start = new DateTimeImmutable('2026-08-10T00:00:00-07:00');
        for ($i = 0; $i < 500; ++$i) {
            $time = $start->modify('+' . $i . ' minutes');
            $slots[] = new TimeSlot($time, $time->modify('+30 seconds'));
        }
        $repository = new SqlBookingRepository($f->sql);
        foreach (array_chunk($slots, 250) as $chunk) {
            $repository->add(new Booking(EntityId::generate(), $room, $f->access->actor, 'Fictional meetings', array_map(BookingOccurrence::plan(...), $chunk)));
        }
        $result = $this->service($f)->week($room, '2026-08-10');
        self::assertCount(500, $result['items']);
        self::assertSame(['approved' => 0, 'requested' => 500], $result['totals']);
        $this->booking($f, $room, '2026-08-10T23:00:00-07:00', '2026-08-10T23:01:00-07:00');
        try {
            $this->service($f)->week($room, '2026-08-10');
            self::fail('An incomplete week was returned.');
        } catch (ValidationException $error) {
            self::assertSame('week', $error->errors[0]['field']);
            self::assertStringContainsString('paged room agenda', $error->errors[0]['message']);
        }
    }

    public function testOwnersCannotReadRoomTimetablesWithoutSchedulingPermission(): void
    {
        $f = new SisFixture();
        $room = $this->room($f);
        $this->booking($f, $room, '2026-08-10T09:00:00Z', '2026-08-10T10:00:00Z');
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $this->service($f)->week($room);
    }

    public function testPermissionIsRecheckedAfterTheBoundedRead(): void
    {
        $f = new SisFixture();
        $room = EntityId::generate();
        $repository = $this->createMock(RoomRepository::class);
        $repository->method('get')->willReturn(new Room($room, 'Fictional room', 30, null, null));
        $repository->expects(self::once())->method('agenda')->with($room, self::anything(), null, 501)
            ->willReturnCallback(static function () use ($f): array {
                $f->access->staff = false;
                return [];
            });
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $repository)->week($room);
    }

    public function testInvalidDatesAreActionableFieldErrors(): void
    {
        foreach (['2026-02-30', '2026-2-03', '2026-03-08T00:00:00Z', '0000-01-01', '9999-12-31', ''] as $date) {
            try {
                new RoomTimetable($date, new DateTimeZone('UTC'));
                self::fail('An invalid date was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('week', $error->errors[0]['field']);
            }
        }
    }
}
