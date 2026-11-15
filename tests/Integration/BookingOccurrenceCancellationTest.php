<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\Service\RoomService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlBuildingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class BookingOccurrenceCancellationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $f, ?AuditTrail $audit = null): BookingService
    {
        return new BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            new SqlRoomRepository($f->sql),
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
    }

    private function rooms(SisFixture $f): RoomService
    {
        return new RoomService(
            new SqlRoomRepository($f->sql),
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->clock,
            new SqlBuildingRepository($f->sql)
        );
    }

    private function booking(SisFixture $f): Booking
    {
        $times = [];
        foreach ([10, 12, 14] as $day) {
            $times[] = new TimeSlot(
                new DateTimeImmutable('2026-08-' . $day . 'T09:00:00-07:00'),
                new DateTimeImmutable('2026-08-' . $day . 'T10:00:00-07:00')
            );
        }
        return $this->service($f)->request($f->room(EntityId::generate()->value, 20), 'Fictional seminar', $times);
    }

    public function testCancellingOneApprovedMeetingPreservesOthersAndRemovesOnlyItsReservation(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $requested = $this->booking($f);
        $approved = $service->approve($requested->id, 0);
        $before = $f->sql->rows('sis_booking_slots', ['booking_id' => $approved->id->value]);
        $target = $approved->occurrences[1];
        $actor = $f->access->actor;
        $result = $service->cancelOccurrence($approved->id, $target->id, 1);
        self::assertSame('approved', $result->status);
        self::assertSame(2, $result->version);
        self::assertSame($actor->value, $result->occurrence($target->id)->cancelledBy?->value);
        self::assertNotNull($result->occurrence($target->id)->cancelledAt);
        $stored = $service->get($approved->id);
        self::assertCount(3, $stored->occurrences);
        self::assertNull($stored->occurrences[0]->cancelledAt);
        self::assertNull($stored->occurrences[2]->cancelledAt);
        foreach ($before as $row) {
            $current = $f->sql->one('sis_booking_slots', ['id' => $row['id']]);
            foreach (['id', 'room_id', 'booking_id', 'starts_at', 'ends_at'] as $field) {
                self::assertSame($row[$field], $current[$field]);
            }
        }
        $rooms = $this->rooms($f);
        self::assertTrue($rooms->availability($approved->roomId, $target->time)['available']);
        self::assertFalse($rooms->availability($approved->roomId, $stored->occurrences[0]->time)['available']);
        self::assertSame(
            ['approved' => 2, 'requested' => 0],
            $rooms->timetable($approved->roomId, '2026-08-12')['totals']
        );
        $f->clock->time = $f->clock->time->modify('+1 day');
        self::assertSame(2, $service->cancelOccurrence($approved->id, $target->id, 1)->version);
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'booking.occurrence_cancelled']));
        self::assertSame([], $f->sql->rows('sis_history_events'));
        $replacement = $service->request($approved->roomId, 'Fictional replacement reservation', [$target->time]);
        self::assertSame('approved', $service->approve($replacement->id, 0)->status);
    }

    public function testPendingCancellationsDoNotReappearAtApprovalAndLastCancellationClosesBooking(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $booking = $this->booking($f);
        $first = $booking->occurrences[0];
        $service->cancelOccurrence($booking->id, $first->id, 0);
        $competing = $service->request($booking->roomId, 'Fictional other meeting', [$first->time]);
        $service->approve($competing->id, 0);
        $approved = $service->approve($booking->id, 1);
        self::assertSame('approved', $approved->status);
        self::assertNotNull($approved->occurrence($first->id)->cancelledAt);
        $second = $service->cancelOccurrence($booking->id, $booking->occurrences[1]->id, 2);
        self::assertSame('approved', $second->status);
        try {
            $service->cancelOccurrence($booking->id, $booking->occurrences[2]->id, 2);
            self::fail('A stale cancellation changed another meeting.');
        } catch (ConcurrentModificationException) {
            self::assertNull($service->get($booking->id)->occurrences[2]->cancelledAt);
        }
        $last = $service->cancelOccurrence($booking->id, $booking->occurrences[2]->id, 3);
        self::assertSame('cancelled', $last->status);
        self::assertSame(4, $last->version);
        self::assertSame(4, $service->cancelOccurrence($booking->id, $first->id, 0)->version);
        self::assertCount(3, $f->sql->rows('sis_booking_slots', ['booking_id' => $booking->id->value]));
    }

    public function testOwnerMayCancelTheirMeetingButCannotTargetAnotherBookingOrPerson(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $booking = $this->booking($f);
        $other = $this->booking($f);
        $f->access->staff = false;
        try {
            $service->cancelOccurrence($booking->id, $other->occurrences[0]->id, 0);
            self::fail('A meeting identity was adopted from another booking.');
        } catch (RecordNotFoundException) {
            self::assertSame(0, $service->get($booking->id)->version);
        }
        self::assertSame(1, $service->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 0)->version);
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->cancelOccurrence($booking->id, $booking->occurrences[1]->id, 1);
    }

    public function testAuditFailureOrAuthorityRevocationRollsBackBothParentAndMeeting(): void
    {
        foreach (['failure', 'revoked'] as $scenario) {
            $f = new SisFixture();
            $booking = $this->booking($f);
            $other = $f->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($f, $scenario, $other): void {
                if ($scenario === 'failure') {
                    throw new RuntimeException('Fictional audit failure');
                }
                $f->access->actor = $other;
                $f->access->staff = false;
            });
            try {
                $this->service($f, $audit)->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 0);
                self::fail('An unverified meeting cancellation committed.');
            } catch (RuntimeException) {
                $stored = new SqlBookingRepository($f->sql)->get($booking->id);
                self::assertSame(0, $stored->version);
                self::assertNull($stored->occurrences[0]->cancelledAt);
                self::assertSame('requested', $stored->status);
            }
        }
    }

    public function testWholeBookingCancellationDoesNotChangeAnEarlierIndividualCancellation(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $booking = $this->booking($f);
        $service->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 0);
        $before = $f->sql->rows('sis_booking_slots', ['booking_id' => $booking->id->value]);
        $service->cancel($booking->id, 1);
        self::assertSame($before, $f->sql->rows('sis_booking_slots', ['booking_id' => $booking->id->value]));
        self::assertSame(2, $service->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 0)->version);
    }
}
