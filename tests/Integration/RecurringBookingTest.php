<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use SisFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\BookingSeries;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use Domain\Shared\ValueObject\EntityId;

final class RecurringBookingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function service(SisFixture $s, ?AuditTrail $audit = null): BookingService
    {
        return new BookingService(
            new SqlBookingRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            new SqlRoomRepository($s->sql),
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository($s->sql),
            $s->bookingSections()
        );
    }
    private function pattern(): BookingPattern
    {
        return new BookingPattern(
            '2026-03-02',
            '2026-03-25',
            '10:00',
            '11:00',
            [1, 3],
            1,
            ['2026-03-11'],
            false,
            'America/Los_Angeles'
        );
    }
    private function school(): SisFixture
    {
        $s = new SisFixture();
        $s->clock->time = new DateTimeImmutable('2026-03-01T10:00:00', new DateTimeZone('America/Los_Angeles'));
        return $s;
    }
    public function testNamedWeekdaysClosuresAndClockChangesProduceExplicitFiniteMeetings(): void
    {
        $slots = new BookingSeries()->pattern($this->pattern());
        self::assertCount(7, $slots);
        self::assertSame('2026-03-02 10:00 -08:00', $slots[0]->startsAt->format('Y-m-d H:i P'));
        self::assertSame('2026-03-09 10:00 -07:00', $slots[2]->startsAt->format('Y-m-d H:i P'));
        self::assertSame('2026-03-25', $slots[6]->startsAt->format('Y-m-d'));
        self::assertNotContains('2026-03-11', array_map(fn($s) => $s->startsAt->format('Y-m-d'), $slots));
        $alternate = new BookingPattern(
            '2026-03-04',
            '2026-03-31',
            '10:00',
            '11:00',
            [1, 3],
            2,
            [],
            false,
            'America/Los_Angeles'
        );
        self::assertSame(['2026-03-04', '2026-03-16', '2026-03-18', '2026-03-30'], array_map(
            fn($s) => $s->startsAt->format('Y-m-d'),
            new BookingSeries()->pattern($alternate)
        ));
    }
    public function testOvernightMeetingsRetainLocalEndDatesAndActualElapsedTime(): void
    {
        $pattern = new BookingPattern(
            '2026-10-31',
            '2026-10-31',
            '23:00',
            '03:00',
            [6],
            1,
            [],
            true,
            'America/Los_Angeles'
        );
        $slot = new BookingSeries()->pattern($pattern)[0];
        self::assertSame('2026-11-01 03:00 -08:00', $slot->endsAt->format('Y-m-d H:i P'));
        self::assertSame(5 * 3600, $slot->endsAt->getTimestamp() - $slot->startsAt->getTimestamp());
    }
    public function testMissingAndAmbiguousLocalTimesAreRejectedAndCanBeSkippedExplicitly(): void
    {
        foreach ([['2026-03-08', '02:30'], ['2026-11-01', '01:30']] as [$date, $time]) {
            try {
                new BookingSeries()->pattern(new BookingPattern(
                    $date,
                    $date,
                    $time,
                    '04:00',
                    [7],
                    1,
                    [],
                    false,
                    'America/Los_Angeles'
                ));
                self::fail('A clock change silently moved a meeting.');
            } catch (ValidationException $error) {
                self::assertSame('start_time', $error->errors[0]['field']);
            }
        }
        $slots = new BookingSeries()->pattern(new BookingPattern(
            '2026-03-01',
            '2026-03-15',
            '02:30',
            '04:00',
            [7],
            1,
            ['2026-03-08'],
            false,
            'America/Los_Angeles'
        ));
        self::assertCount(2, $slots);
    }
    public function testInvalidOrUnboundedPatternsCannotBecomePartialBookings(): void
    {
        foreach (
            [
            ['2026-01-01', '2027-01-02', '10:00', '11:00', [1], 1, [], false],
            ['2026-02-30', '2026-03-01', '10:00', '11:00', [1], 1, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '09:00', [1], 1, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '11:00', [], 1, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '11:00', [true], 1, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '11:00', [1, 1], 1, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '11:00', [1], 0, [], false],
            ['2026-03-01', '2026-03-02', '10:00', '11:00', [1], 1, ['2026-03-01'], false],
            ] as $args
        ) {
            try {
                new BookingSeries()->pattern(new BookingPattern(...[...$args, 'America/Los_Angeles']));
                self::fail('Invalid pattern accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testReviewIsPrivateAndReadOnlyAndAcceptedSlotsUseTheExistingTablesWithoutNewEvents(): void
    {
        $s = $this->school();
        $service = $this->service($s);
        $room = $s->room('LIB.101', 30);
        $before = [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_audit')];
        $review = $service->review($room, 'Fictional seminar', $this->pattern());
        self::assertCount(7, $review['items']);
        self::assertSame(0, $review['conflicts']);
        self::assertSame([], $s->sql->rows('sis_bookings'));
        self::assertSame($before, [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_audit')]);
        $id = EntityId::generate();
        $created = $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $review['revision']);
        self::assertSame('requested', $created->status);
        self::assertCount(7, $s->sql->rows('sis_booking_slots'));
        self::assertSame($before[0], $s->sql->rows('sis_history_events'));
        $read = $service->get($id);
        self::assertSame('2026-03-09T17:00:00+00:00', $read->occurrences[2]->time->startsAt->format(DATE_ATOM));
        $service->approve($id, 0);
        $service->cancelOccurrence($id, $read->occurrences[0]->id, 1);
        $retry = $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $review['revision']);
        self::assertSame(2, $retry->version);
        self::assertNotNull($retry->occurrences[0]->cancelledAt);
        $service->cancel($id, 2);
        $rows = $s->sql->rows('sis_booking_slots');
        self::assertSame('cancelled', $service->requestSeries(
            $id,
            $room,
            'Fictional seminar',
            $this->pattern(),
            $review['revision']
        )->status);
        self::assertSame($rows, $s->sql->rows('sis_booking_slots'));
        self::assertCount(1, $s->sql->rows('sis_bookings'));
    }
    public function testConflictsAreMinimizedAndAChangedApprovalRequiresFreshReview(): void
    {
        $s = $this->school();
        $service = $this->service($s);
        $room = $s->room('LIB.101', 30);
        $review = $service->review($room, 'Fictional seminar', $this->pattern());
        $other = $service->request(
            $room,
            'Private competing title',
            [new BookingSeries()->pattern($this->pattern())[0]]
        );
        $service->approve($other->id, 0);
        $before = $s->sql->rows('sis_booking_slots');
        try {
            $service->requestSeries(
                EntityId::generate(),
                $room,
                'Fictional seminar',
                $this->pattern(),
                $review['revision']
            );
            self::fail('Changed availability was not reviewed again.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_booking_slots'));
        }
        $fresh = $service->review($room, 'Fictional seminar', $this->pattern());
        self::assertSame(1, $fresh['conflicts']);
        self::assertStringNotContainsString('Private competing title', json_encode($fresh, JSON_THROW_ON_ERROR));
        $requested = $service->requestSeries(
            EntityId::generate(),
            $room,
            'Fictional seminar',
            $this->pattern(),
            $fresh['revision']
        );
        $this->expectException(DomainException::class);
        $service->approve($requested->id, 0);
    }
    public function testAuditFailureRollsBackAllOccurrencesAndSchedulersCannotAdoptAnotherOwnersRetry(): void
    {
        $s = $this->school();
        $service = $this->service($s);
        $room = $s->room('LIB.101', 30);
        $review = $service->review($room, 'Fictional seminar', $this->pattern());
        $id = EntityId::generate();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        try {
            $this->service($s, $audit)->requestSeries(
                $id,
                $room,
                'Fictional seminar',
                $this->pattern(),
                $review['revision']
            );
            self::fail('Unaudited occurrences committed.');
        } catch (RuntimeException) {
            self::assertSame([], $s->sql->rows('sis_booking_slots'));
            self::assertSame([], $s->sql->rows('sis_bookings'));
        }
        $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $review['revision']);
        $s->access->actor = $s->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $review['revision']);
    }
    public function testBookingOwnershipDoesNotGrantRoomPlanningAuthority(): void
    {
        $s = $this->school();
        $service = $this->service($s);
        $room = $s->room('LIB.101', 30);
        $own = $service->request($room, 'Own request', [new BookingSeries()->pattern($this->pattern())[0]]);
        $s->access->staff = false;
        self::assertSame($own->id->value, $service->get($own->id)->id->value);
        $this->expectException(AccessDeniedException::class);
        $service->review($room, 'Own request', $this->pattern());
    }

    public function testChangedRoomMetadataAndTimezoneRequireFreshReviewButOldSuccessfulRetriesRemainSafe(): void
    {
        $s = $this->school();
        $service = $this->service($s);
        $room = $s->room('LIB.101', 30);
        $review = $service->review($room, 'Fictional seminar', $this->pattern());
        $s->sql->update('sis_rooms', ['capacity' => 35], ['id' => $room->value]);
        try {
            $service->requestSeries(
                EntityId::generate(),
                $room,
                'Fictional seminar',
                $this->pattern(),
                $review['revision']
            );
            self::fail('Changed room metadata was accepted without review.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_bookings'));
        }
        $fresh = $service->review($room, 'Fictional seminar', $this->pattern());
        $id = EntityId::generate();
        $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $fresh['revision']);
        $s->clock->time = $s->clock->time->setTimezone(new DateTimeZone('UTC'));
        self::assertSame($id->value, $service->requestSeries(
            $id,
            $room,
            'Fictional seminar',
            $this->pattern(),
            $fresh['revision']
        )->id->value);
        $this->expectException(ValidationException::class);
        $service->review($room, 'Another seminar', $this->pattern());
    }

    public function testAnAccountOrSchedulerChangeDuringMutationRollsBackTheWholeRequestOrApproval(): void
    {
        foreach (['request', 'approve'] as $action) {
            $s = $this->school();
            $service = $this->service($s);
            $room = $s->room('LIB.101', 30);
            $review = $service->review($room, 'Fictional seminar', $this->pattern());
            $id = EntityId::generate();
            if ($action === 'approve') {
                $service->requestSeries($id, $room, 'Fictional seminar', $this->pattern(), $review['revision']);
            }
            $before = [$s->sql->rows('sis_bookings'), $s->sql->rows('sis_booking_slots'), $s->sql->rows('sis_audit')];
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s): void {
                $s->access->staff = false;
            });
            try {
                if ($action === 'approve') {
                    $this->service($s, $audit)->approve($id, 0);
                } else {
                    $this->service($s, $audit)->requestSeries(
                        $id,
                        $room,
                        'Fictional seminar',
                        $this->pattern(),
                        $review['revision']
                    );
                }
                self::fail('Revoked scheduling authority committed a mutation.');
            } catch (AccessDeniedException) {
                self::assertSame($before, [
                    $s->sql->rows('sis_bookings'), $s->sql->rows('sis_booking_slots'), $s->sql->rows('sis_audit')
                ]);
            }
        }
    }
}
