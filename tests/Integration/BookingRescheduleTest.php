<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class BookingRescheduleTest extends TestCase
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
            $f->clock
        );
    }

    private function time(int $hour, int $day = 10): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable("2026-08-$day $hour:00:00-07:00"),
            new DateTimeImmutable("2026-08-$day " . ($hour + 1) . ':00:00-07:00')
        );
    }

    /** @return array{Booking,EntityId} */
    private function source(SisFixture $f, bool $approved = true): array
    {
        $booking = $this->service($f)->request(
            $f->room('LIB.101', 30),
            'Fictional workshop',
            [$this->time(9), $this->time(10), $this->time(11)]
        );
        return [$approved ? $this->service($f)->approve($booking->id, 0) : $booking, $f->room('SCI.101', 20)];
    }

    public function testReviewedMoveAtomicallyReleasesOnlyTheSelectedMeetingAndPreservesItsHistory(): void
    {
        $f = new SisFixture();
        [$source, $room] = $this->source($f);
        $f->access->actor = $f->user();
        $service = $this->service($f);
        $occurrence = $source->occurrences[0];
        $before = $f->sql->one('sis_booking_slots', ['id' => $occurrence->id->value]);
        $auditBefore = $f->sql->rows('sis_audit');
        $review = $service->reviewReplacement($source->id, $occurrence->id, $room, $this->time(14), 1);
        self::assertTrue($review['available']);
        self::assertSame($auditBefore, $f->sql->rows('sis_audit'));
        self::assertSame([], $f->sql->rows('sis_booking_replacements'));
        self::assertTrue(new SqlBookingRepository($f->sql)->conflicts($source->roomId, $occurrence->time));
        $replacement = $service->replaceOccurrence(
            $source->id,
            $occurrence->id,
            new EntityId($review['new_id']),
            $room,
            $this->time(14),
            1,
            $review['revision']
        );
        self::assertSame('approved', $replacement->status);
        self::assertSame($source->ownerUserId->value, $replacement->ownerUserId->value);
        $current = $service->get($source->id);
        self::assertSame('approved', $current->status);
        self::assertSame(2, $current->version);
        self::assertNull($current->occurrences[1]->cancelledAt);
        self::assertNotNull($current->occurrence($occurrence->id)->cancelledAt);
        $after = $f->sql->one('sis_booking_slots', ['id' => $occurrence->id->value]);
        foreach (['id', 'booking_id', 'room_id', 'starts_at', 'ends_at'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertFalse(new SqlBookingRepository($f->sql)->conflicts($source->roomId, $occurrence->time));
        self::assertTrue(new SqlBookingRepository($f->sql)->conflicts($room, $this->time(14)));
        self::assertSame($replacement->id->value, $service->view($source->id)['replacements'][$occurrence->id->value]);
        self::assertSame($source->id->value, $service->view($replacement->id)['source_booking_id']);
        self::assertCount(1, $f->sql->rows('sis_booking_replacements'));
        self::assertSame([], $f->sql->rows('sis_history_events'));
    }

    public function testSameRoomOverlapExcludesOnlyTheMovedOccurrenceAndRechecksNewReservations(): void
    {
        $f = new SisFixture();
        [$source, $room] = $this->source($f);
        $service = $this->service($f);
        $id = $source->occurrences[0]->id;
        $overlapping = new TimeSlot(
            new DateTimeImmutable('2026-08-10T09:30:00-07:00'),
            new DateTimeImmutable('2026-08-10T09:45:00-07:00')
        );
        self::assertTrue($service->reviewReplacement($source->id, $id, $source->roomId, $overlapping, 1)['available']);
        $conflict = $service->reviewReplacement($source->id, $id, $source->roomId, $this->time(10), 1);
        self::assertFalse($conflict['available']);
        try {
            $service->replaceOccurrence(
                $source->id,
                $id,
                new EntityId($conflict['new_id']),
                $source->roomId,
                $this->time(10),
                1,
                $conflict['revision']
            );
            self::fail('Another active meeting in the same series was excluded from conflicts.');
        } catch (ValidationException) {
            self::assertNull($service->get($source->id)->occurrence($id)->cancelledAt);
        }
        $review = $service->reviewReplacement($source->id, $id, $room, $this->time(14), 1);
        $other = $service->request($room, 'Fictional competing reservation', [$this->time(14)]);
        $service->approve($other->id, 0);
        $this->expectException(ConcurrentModificationException::class);
        try {
            $service->replaceOccurrence(
                $source->id,
                $id,
                new EntityId($review['new_id']),
                $room,
                $this->time(14),
                1,
                $review['revision']
            );
        } finally {
            self::assertNull($service->get($source->id)->occurrence($id)->cancelledAt);
            self::assertSame([], $f->sql->rows('sis_booking_replacements'));
        }
    }

    public function testPendingMoveStaysPendingAndRetriesDoNotUndoLaterDecisionsOrDuplicateBookings(): void
    {
        $f = new SisFixture();
        [$source, $room] = $this->source($f, false);
        $service = $this->service($f);
        $occurrence = $source->occurrences[0]->id;
        $review = $service->reviewReplacement($source->id, $occurrence, $room, $this->time(14), 0);
        $id = new EntityId($review['new_id']);
        $moved = $service->replaceOccurrence(
            $source->id,
            $occurrence,
            $id,
            $room,
            $this->time(14),
            0,
            $review['revision']
        );
        self::assertSame('requested', $moved->status);
        $service->cancel($source->id, 1);
        $service->cancel($moved->id, 0);
        $audits = $f->sql->rows('sis_audit');
        $retry = $service->replaceOccurrence(
            $source->id,
            $occurrence,
            $id,
            $room,
            $this->time(14),
            0,
            $review['revision']
        );
        self::assertSame('cancelled', $retry->status);
        self::assertSame($audits, $f->sql->rows('sis_audit'));
        self::assertCount(2, $f->sql->rows('sis_bookings'));
        self::assertCount(1, $f->sql->rows('sis_booking_replacements'));
        $f->access->actor = $f->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->replaceOccurrence($source->id, $occurrence, $id, $room, $this->time(14), 0, $review['revision']);
    }

    public function testRoomChangesAndStaleSourceVersionsRequireAnotherReview(): void
    {
        $f = new SisFixture();
        [$source, $room] = $this->source($f);
        $service = $this->service($f);
        $occurrence = $source->occurrences[0]->id;
        $review = $service->reviewReplacement($source->id, $occurrence, $room, $this->time(14), 1);
        $f->sql->update('sis_rooms', ['name' => 'Fictional renamed room'], ['id' => $room->value]);
        try {
            $service->replaceOccurrence(
                $source->id,
                $occurrence,
                new EntityId($review['new_id']),
                $room,
                $this->time(14),
                1,
                $review['revision']
            );
            self::fail('Changed room metadata bypassed review.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_booking_replacements'));
        }
        $service->cancelOccurrence($source->id, $source->occurrences[2]->id, 1);
        $this->expectException(ConcurrentModificationException::class);
        $service->reviewReplacement($source->id, $occurrence, $room, $this->time(14), 1);
    }

    public function testNoChangeUnknownMeetingsAndOwnerOnlyAccessCannotBypassSchedulingAuthority(): void
    {
        $f = new SisFixture();
        [$source, $room] = $this->source($f);
        $service = $this->service($f);
        $occurrence = $source->occurrences[0];
        try {
            $service->reviewReplacement($source->id, $occurrence->id, $source->roomId, $occurrence->time, 1);
            self::fail('A replacement without any change was reviewed.');
        } catch (ValidationException $error) {
            self::assertSame('starts_at', $error->errors[0]['field']);
        }
        try {
            $service->reviewReplacement($source->id, EntityId::generate(), $room, $this->time(14), 1);
            self::fail('An unknown meeting was reviewed.');
        } catch (RecordNotFoundException) {
            self::assertSame([], $f->sql->rows('sis_booking_replacements'));
        }
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->reviewReplacement($source->id, $occurrence->id, $room, $this->time(14), 1);
    }

    public function testAuditFailureOrRevokedAuthorityRollsBackTheOldReservationAndReplacementTogether(): void
    {
        foreach (['audit', 'authority'] as $scenario) {
            $f = new SisFixture();
            [$source, $room] = $this->source($f);
            $actor = $f->user();
            $service = $this->service($f);
            $occurrence = $source->occurrences[0]->id;
            $review = $service->reviewReplacement($source->id, $occurrence, $room, $this->time(14), 1);
            $audits = $f->sql->rows('sis_audit');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($f, $scenario, $actor): void {
                if ($scenario === 'audit') {
                    throw new RuntimeException('Fictional audit failure');
                }
                $f->access->staff = false;
                $f->access->actor = $actor;
            });
            try {
                $this->service($f, $audit)->replaceOccurrence(
                    $source->id,
                    $occurrence,
                    new EntityId($review['new_id']),
                    $room,
                    $this->time(14),
                    1,
                    $review['revision']
                );
                self::fail('A partially verified reschedule committed.');
            } catch (RuntimeException) {
                $stored = new SqlBookingRepository($f->sql)->get($source->id);
                self::assertSame(1, $stored->version);
                self::assertNull($stored->occurrence($occurrence)->cancelledAt);
                self::assertCount(1, $f->sql->rows('sis_bookings'));
                self::assertSame([], $f->sql->rows('sis_booking_replacements'));
                self::assertSame($audits, $f->sql->rows('sis_audit'));
            }
        }
    }

    public function testMovingTheLastMeetingClosesItsOriginalBookingAndFurtherMovesRetainTheChain(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $room = $f->room('LIB.101', 30);
        $original = $service->request($room, 'Fictional single meeting', [$this->time(9)]);
        $service->approve($original->id, 0);
        $review = $service->reviewReplacement($original->id, $original->occurrences[0]->id, $room, $this->time(14), 1);
        $first = $service->replaceOccurrence(
            $original->id,
            $original->occurrences[0]->id,
            new EntityId($review['new_id']),
            $room,
            $this->time(14),
            1,
            $review['revision']
        );
        self::assertSame('cancelled', $service->get($original->id)->status);
        $nextReview = $service->reviewReplacement($first->id, $first->occurrences[0]->id, $room, $this->time(15), 1);
        $second = $service->replaceOccurrence(
            $first->id,
            $first->occurrences[0]->id,
            new EntityId($nextReview['new_id']),
            $room,
            $this->time(15),
            1,
            $nextReview['revision']
        );
        self::assertSame('approved', $second->status);
        self::assertSame($first->id->value, $service->view($second->id)['source_booking_id']);
        $links = $service->view($first->id)['replacements'];
        self::assertSame($second->id->value, $links[$first->occurrences[0]->id->value]);
        self::assertSame('cancelled', $service->replaceOccurrence(
            $original->id,
            $original->occurrences[0]->id,
            $first->id,
            $room,
            $this->time(14),
            1,
            $review['revision']
        )->status);
        self::assertCount(3, $f->sql->rows('sis_bookings'));
        self::assertCount(2, $f->sql->rows('sis_booking_replacements'));
    }
}
