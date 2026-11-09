<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\Service\RoomService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;

final class RoomPlanningTest extends TestCase
{
    private function rooms(SisFixture $f, ?AuditTrail $audit = null): RoomService
    {
        return new RoomService(
            new SqlRoomRepository($f->sql),
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    private function bookings(SisFixture $f): BookingService
    {
        return new BookingService(new SqlBookingRepository($f->sql), $f->access, $f->transactions, $f->audit);
    }

    private function slot(string $start, string $end): TimeSlot
    {
        return new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function testRoomEditsRejectStaleSavesAndPreserveBookingKeys(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $id = $bookings->addRoom(' Library.101 ', 30);
        $booking = $bookings->request($id, 'Fictional seminar', [$this->slot('2026-08-11T09:00:00Z', '2026-08-11T10:00:00Z')]);
        $before = $rooms->view($id);
        self::assertSame('Library.101', $before['code']);
        $rooms->edit($id, 'Library.102', 40, $before['revision']);
        self::assertSame(40, $rooms->view($id)['capacity']);
        self::assertSame($id->value, $bookings->get($booking->id)->roomId->value);
        try {
            $rooms->edit($id, 'Stale', 20, $before['revision']);
            self::fail('Stale room edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Library.102', $rooms->view($id)['code']);
        }
        $audit = $f->sql->rows('sis_audit', ['action' => 'room.updated']);
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('Library', json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function testAvailabilityIsAdvisoryAndUsesHalfOpenUtcIntervals(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $id = $bookings->addRoom('ROOM.1', 20);
        $window = $this->slot('2026-08-11T09:00:00-07:00', '2026-08-11T10:00:00-07:00');
        $one = $bookings->request($id, 'Fictional seminar', [$window]);
        $two = $bookings->request($id, 'Fictional competing request', [$window]);
        self::assertTrue($rooms->availability($id, $window)['available']);
        $bookings->approve($one->id, 0);
        self::assertFalse($rooms->availability($id, $this->slot('2026-08-11T16:30:00Z', '2026-08-11T17:30:00Z'))['available']);
        self::assertTrue($rooms->availability($id, $this->slot('2026-08-11T17:00:00Z', '2026-08-11T18:00:00Z'))['available']);
        try {
            $bookings->approve($two->id, 0);
            self::fail('An advisory availability result bypassed conflict checks.');
        } catch (DomainException) {
            self::assertSame('requested', $bookings->get($two->id)->status);
        }
        try {
            $bookings->cancel($one->id, 0);
            self::fail('A stale cancellation was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('approved', $bookings->get($one->id)->status);
        }
        $bookings->cancel($one->id, 1);
        self::assertTrue($rooms->availability($id, $window)['available']);
    }

    public function testAgendaPagesByTimeThenIdAndPreservesTiesAfterCancellation(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $id = $bookings->addRoom('ROOM.1', 20);
        $later = $bookings->request($id, 'Later occurrence', [$this->slot('2026-08-11T18:00:00Z', '2026-08-11T19:00:00Z')]);
        for ($i = 0; $i < 50; ++$i) {
            $bookings->request($id, 'Fictional concurrent request', [$this->slot('2026-08-11T09:00:00Z', '2026-08-11T10:00:00Z')]);
        }
        $window = $this->slot('2026-08-11T00:00:00Z', '2026-08-12T00:00:00Z');
        $first = $rooms->agenda($id, $window);
        self::assertCount(50, $first['items']);
        self::assertNotNull($first['next_after']);
        self::assertSame('2026-08-11T09:00:00+00:00', $first['items'][0]['starts_at']);
        $cursor = new EntityId($first['next_after']);
        $bookings->cancel(new EntityId($first['items'][49]['booking_id']), 0);
        $second = $rooms->agenda($id, $window, $cursor);
        self::assertCount(1, $second['items']);
        self::assertSame($later->id->value, $second['items'][0]['booking_id']);
        self::assertNull($second['next_after']);
        self::assertCount(50, $rooms->agenda($id, $window)['items']);
        $different = $bookings->addRoom('OTHER.1', 10);
        $this->expectException(RecordNotFoundException::class);
        $rooms->agenda($different, $window, $cursor);
    }

    public function testRoomPlanningRequiresSchedulingPermissionEvenForBookingOwner(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $id = $bookings->addRoom('ROOM.1', 20);
        $window = $this->slot('2026-08-11T09:00:00Z', '2026-08-11T10:00:00Z');
        $bookings->request($id, 'Fictional seminar', [$window]);
        $revision = $rooms->view($id)['revision'];
        $f->access->staff = false;
        foreach (
            [fn () => $rooms->view($id), fn () => $rooms->edit($id, 'Changed', 10, $revision),
            fn () => $rooms->agenda($id, $window), fn () => $rooms->availability($id, $window)] as $readOrWrite
        ) {
            try {
                $readOrWrite();
                self::fail('Booking ownership exposed room planning.');
            } catch (AccessDeniedException) {
                self::assertSame('ROOM.1', $f->sql->one('sis_rooms', ['id' => $id->value])['code']);
            }
        }
    }

    public function testSearchWindowsAndRoomMetadataAreBounded(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $id = $bookings->addRoom('ROOM.1', 20);
        $revision = $rooms->view($id)['revision'];
        foreach (
            [fn () => $rooms->edit($id, "Room\nName", 20, $revision),
            fn () => $rooms->edit($id, 'ROOM.1', 0, $revision),
            fn () => $rooms->availability($id, $this->slot('2026-08-01T00:00:00Z', '2026-09-02T00:00:00Z')),
            fn () => $rooms->agenda($id, $this->slot('2026-08-01T00:00:00Z', '2026-09-02T00:00:00Z'))] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Invalid room or unbounded search was accepted.');
            } catch (ValidationException) {
                self::assertSame(20, $rooms->view($id)['capacity']);
            }
        }
    }

    public function testRoomEditRollsBackIfMetadataAuditFails(): void
    {
        $f = new SisFixture();
        $id = $this->bookings($f)->addRoom('ROOM.1', 20);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('fictional audit failure'));
        $rooms = $this->rooms($f, $audit);
        try {
            $rooms->edit($id, 'Changed', 10, $rooms->view($id)['revision']);
            self::fail('An unaudited edit committed.');
        } catch (RuntimeException) {
            self::assertSame('ROOM.1', $rooms->view($id)['code']);
        }
    }

    public function testDuplicateRoomCodesBecomeFieldErrorsAndLeaveRecordsUnchanged(): void
    {
        $f = new SisFixture();
        $bookings = $this->bookings($f);
        $rooms = $this->rooms($f);
        $one = $bookings->addRoom('ROOM.1', 20);
        $two = $bookings->addRoom('ROOM.2', 30);
        $revision = $rooms->view($two)['revision'];
        foreach (
            [fn () => $bookings->addRoom('ROOM.1', 10),
            fn () => $rooms->edit($two, 'ROOM.1', 10, $revision)] as $duplicate
        ) {
            try {
                $duplicate();
                self::fail('A duplicate room code was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('code', $error->errors[0]['field']);
                self::assertSame('ROOM.1', $rooms->view($one)['code']);
                self::assertSame('ROOM.2', $rooms->view($two)['code']);
                self::assertSame(30, $rooms->view($two)['capacity']);
            }
        }
    }
}
