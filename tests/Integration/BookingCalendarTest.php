<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\Building;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\Room;
use Domain\Scheduling\Service\BookingCalendarService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlBuildingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class BookingCalendarTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $f, ?RoomRepository $rooms = null): BookingCalendarService
    {
        return new BookingCalendarService(
            $rooms ?? new SqlRoomRepository($f->sql),
            new SqlBuildingRepository($f->sql),
            $f->access,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository($f->sql)
        );
    }

    private function room(SisFixture $f, string $code, ?EntityId $building = null): EntityId
    {
        $id = EntityId::generate();
        new SqlRoomRepository($f->sql)->add(new Room($id, $code, 30, 'Fictional ' . $code, $building));
        return $id;
    }

    private function meeting(
        SisFixture $f,
        EntityId $room,
        string $start,
        string $end,
        string $status = 'approved'
    ): Booking {
        $booking = new Booking(
            EntityId::generate(),
            $room,
            $f->access->actor,
            'Fictional seminar',
            [BookingOccurrence::plan(new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end)))],
            $status
        );
        new SqlBookingRepository($f->sql)->add($booking);
        return $booking;
    }

    public function testCalendarJoinsRoomAndBuildingLabelsAndKeepsReadsPrivateAndUnchanged(): void
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2026-03-08T18:00:00', new DateTimeZone('America/Los_Angeles'));
        $library = $f->building();
        $science = new Building(EntityId::generate(), 'SCI', 'Science building');
        new SqlBuildingRepository($f->sql)->add($science);
        $a = $this->room($f, 'LIB.101', $library);
        $b = $this->room($f, 'SCI.201', $science->id);
        $unassigned = $this->room($f, 'EARLIER.1');
        $overnight = $this->meeting($f, $a, '2026-03-01T23:00:00-08:00', '2026-03-02T01:00:00-08:00');
        $request = $this->meeting($f, $b, '2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00', 'requested');
        $this->meeting($f, $unassigned, '2026-03-05T12:00:00Z', '2026-03-05T13:00:00Z');
        $this->meeting($f, $a, '2026-03-06T12:00:00Z', '2026-03-06T13:00:00Z', 'cancelled');
        $removed = $this->meeting($f, $b, '2026-03-04T12:00:00Z', '2026-03-04T13:00:00Z');
        $cancelled = $removed->cancelOccurrence($removed->occurrences[0]->id, $f->access->actor, $f->clock->now());
        new SqlBookingRepository($f->sql)->cancelOccurrence($cancelled, $removed->occurrences[0]->id, 0);
        // Exact end-of-week starts and exact beginning-of-week endings are excluded.
        $this->meeting($f, $a, '2026-03-09T00:00:00-07:00', '2026-03-09T01:00:00-07:00');
        $this->meeting($f, $a, '2026-03-01T22:00:00-08:00', '2026-03-02T00:00:00-08:00');
        $tables = ['sis_bookings', 'sis_booking_slots', 'sis_rooms', 'sis_buildings',
            'sis_history_events', 'sis_audit'];
        $before = array_map(fn(string $table): array => $f->sql->rows($table), $tables);
        $service = $this->service($f);
        self::assertSame(
            ['today' => '2026-03-08', 'timezone' => 'America/Los_Angeles'],
            $service->context()
        );
        $result = $service->timetable();
        self::assertSame(['approved' => 2, 'requested' => 1], $result['totals']);
        self::assertCount(7, $result['days']);
        self::assertCount(3, $result['items']);
        self::assertSame($overnight->id->value, $result['items'][0]['booking_id']);
        self::assertTrue($result['days'][0]['items'][0]['continues_before']);
        self::assertSame('2026-03-08T01:30:00-08:00', $result['items'][2]['starts_at']);
        self::assertSame('2026-03-08T03:30:00-07:00', $result['items'][2]['ends_at']);
        self::assertArrayNotHasKey('owner_user_id', $result['items'][0]);
        self::assertSame('LIB.101', $result['items'][0]['room_code']);
        self::assertNull($result['items'][1]['building_name']);
        $filtered = $service->timetable(null, $science->id)['items'];
        self::assertSame([$request->id->value], array_column($filtered, 'booking_id'));
        foreach (['sCi.201', 'Science building', 'Fictional SCI'] as $search) {
            $filtered = $service->timetable(null, null, $search)['items'];
            self::assertSame([$request->id->value], array_column($filtered, 'booking_id'));
        }
        self::assertSame([], $service->timetable(null, $library, 'Science')['items']);
        self::assertSame($before, array_map(fn(string $table): array => $f->sql->rows($table), $tables));
    }

    public function testSearchTreatsSqlWildcardCharactersAsLiteralText(): void
    {
        $f = new SisFixture();
        $literal = $this->room($f, 'ROOM_%!');
        $other = $this->room($f, 'ROOM_OTHER');
        $meeting = $this->meeting($f, $literal, '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00');
        $this->meeting($f, $other, '2026-08-10T10:00:00-07:00', '2026-08-10T11:00:00-07:00');
        $filtered = $this->service($f)->timetable(null, null, '_%!')['items'];
        self::assertSame([$meeting->id->value], array_column($filtered, 'booking_id'));
        self::assertSame([], $this->service($f)->timetable(null, null, "' OR 1=1 --")['items']);
    }

    public function testMonthReadsFilterBeforeTheBoundAndUseHalfOpenSchoolCalendarDates(): void
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2026-03-08T18:00:00', new DateTimeZone('America/Los_Angeles'));
        $building = $f->building();
        $a = $this->room($f, 'LIB.101', $building);
        $b = $this->room($f, 'LIB.102', $building);
        $this->meeting($f, $a, '2026-02-28T23:00:00-08:00', '2026-03-01T00:00:00-08:00');
        $carry = $this->meeting($f, $a, '2026-02-28T23:30:00-08:00', '2026-03-01T01:00:00-08:00');
        $dst = $this->meeting($f, $b, '2026-03-08T01:30:00-08:00', '2026-03-08T03:30:00-07:00');
        $tail = $this->meeting($f, $a, '2026-03-31T23:30:00-07:00', '2026-04-01T01:00:00-07:00', 'requested');
        $this->meeting($f, $a, '2026-04-01T00:00:00-07:00', '2026-04-01T01:00:00-07:00');
        $this->meeting($f, $a, '2026-03-10T09:00:00-07:00', '2026-03-10T10:00:00-07:00', 'cancelled');
        $tables = ['sis_bookings', 'sis_booking_slots', 'sis_history_events', 'sis_audit'];
        $before = array_map(fn(string $table): array => $f->sql->rows($table), $tables);
        $result = $this->service($f)->timetable('2026-03-31', $building, 'LIB', 'month');
        self::assertSame('month', $result['view']);
        self::assertSame('2026-03-01', $result['from']);
        self::assertSame('2026-03-31', $result['through']);
        self::assertCount(31, $result['days']);
        self::assertSame(['approved' => 2, 'requested' => 1], $result['totals']);
        self::assertSame(
            [$carry->id->value, $dst->id->value, $tail->id->value],
            array_column($result['items'], 'booking_id')
        );
        self::assertTrue($result['days'][0]['items'][0]['continues_before']);
        self::assertTrue($result['days'][30]['items'][0]['continues_after']);
        self::assertSame('2026-03-08T03:30:00-07:00', $result['items'][1]['ends_at']);
        $filtered = $this->service($f)->timetable('2026-03-08', $building, 'LIB.102', 'month');
        self::assertSame([$dst->id->value], array_column($filtered['items'], 'booking_id'));
        self::assertSame($before, array_map(fn(string $table): array => $f->sql->rows($table), $tables));
    }

    public function testLargeWeeksRejectPartialCountsButCanBeNarrowed(): void
    {
        $f = new SisFixture();
        $room = $this->room($f, 'DENSE.1');
        $start = new DateTimeImmutable('2026-08-10T00:00:00-07:00');
        $occurrences = [];
        for ($i = 0; $i < 500; ++$i) {
            $time = $start->modify('+' . $i . ' minutes');
            $occurrences[] = BookingOccurrence::plan(new TimeSlot($time, $time->modify('+30 seconds')));
        }
        foreach (array_chunk($occurrences, 250) as $chunk) {
            new SqlBookingRepository($f->sql)->add(new Booking(
                EntityId::generate(),
                $room,
                $f->access->actor,
                'Fictional busy week',
                $chunk
            ));
        }
        self::assertCount(500, $this->service($f)->timetable()['items']);
        self::assertCount(500, $this->service($f)->timetable(view: 'month')['items']);
        $quiet = $this->room($f, 'QUIET.1');
        $this->meeting($f, $quiet, '2026-08-11T10:00:00-07:00', '2026-08-11T11:00:00-07:00');
        try {
            $this->service($f)->timetable();
            self::fail('An incomplete institution week was returned.');
        } catch (ValidationException $error) {
            self::assertSame('search', $error->errors[0]['field']);
            self::assertStringContainsString('500', $error->errors[0]['message']);
        }
        self::assertCount(1, $this->service($f)->timetable(null, null, 'QUIET.1')['items']);
        try {
            $this->service($f)->timetable(view: 'month');
            self::fail('An incomplete month was returned.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('week view', $error->errors[0]['message']);
        }
        self::assertCount(1, $this->service($f)->timetable(search: 'QUIET.1', view: 'month')['items']);
        self::assertSame(['cancelled_at', 'ends_at', 'starts_at', 'id'], array_column(
            $f->connection->pdo->query('PRAGMA index_info(sbs_calendar_window)')->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        ));
        $before = $f->sql->rows('sis_booking_slots');
        $migration = new \AddSisBookingCalendarIndex('calendar-index');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($f->connection, 'test_migrations')]));
        $migration->down();
        self::assertSame([], $f->connection->pdo->query('PRAGMA index_info(sbs_calendar_window)')->fetchAll());
        self::assertSame($before, $f->sql->rows('sis_booking_slots'));
    }

    public function testOwnersNeedSchedulingAuthorityAndTheFinalReadRechecksIt(): void
    {
        $f = new SisFixture();
        $f->access->staff = false;
        foreach (
            [fn() => $this->service($f)->context(), fn() => $this->service($f)->timetable(),
            fn() => $this->service($f)->timetable(view: 'month')] as $read
        ) {
            try {
                $read();
                self::fail('An ordinary owner read the campus calendar.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_history_events'));
            }
        }
        $f->access->staff = true;
        $rooms = $this->createMock(RoomRepository::class);
        $rooms->expects(self::once())->method('calendar')->with(self::anything(), null, '', 501, null, 'all')
            ->willReturnCallback(static function () use ($f): array {
                $f->access->staff = false;
                return [];
            });
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $rooms)->timetable();
    }

    public function testInvalidSearchAndUnknownBuildingsAreRejected(): void
    {
        $f = new SisFixture();
        foreach ([str_repeat('a', 101), "line\nbreak", "bad\0text"] as $search) {
            try {
                $this->service($f)->timetable(null, null, $search);
                self::fail('An invalid calendar search was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('search', $error->errors[0]['field']);
            }
        }
        $this->expectException(RecordNotFoundException::class);
        $this->service($f)->timetable(null, EntityId::generate());
    }
}
