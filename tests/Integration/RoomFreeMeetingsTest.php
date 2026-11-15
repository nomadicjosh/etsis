<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\Service\BookingCalendarService;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingCategoryRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlBuildingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RoomFreeMeetingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    /** @return array{SisFixture,EntityId} */
    private function school(DeliveryMode $mode = DeliveryMode::Online): array
    {
        $c = new \CatalogWorkflowFixture();
        $f = $c->sis;
        $f->clock->time = $f->clock->time->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $prototype = $c->plan()->sections[0];
        $section = new Section(
            $prototype->id,
            $prototype->courseId,
            $prototype->termId,
            null,
            $prototype->code,
            30,
            false,
            true,
            $prototype->fee,
            $mode
        );
        $plan = new OfferingPlan($f->term, $section);
        $offerings = $c->offerings();
        $review = $offerings->review($plan);
        $operation = new EntityId($review['operation_id']);
        $offerings->save($operation, $plan, $review['revision']);
        $published = $c->publicationReview($offerings, $operation);
        $offerings->publish($operation, $published['review_token']);
        return [$f, $section->id];
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
            new SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
    }

    private function calendar(SisFixture $f): BookingCalendarService
    {
        return new BookingCalendarService(
            new SqlRoomRepository($f->sql),
            new SqlBuildingRepository($f->sql),
            $f->access,
            $f->clock,
            new SqlBookingCategoryRepository($f->sql)
        );
    }

    private function time(int $day = 10, int $hour = 10): TimeSlot
    {
        $start = new DateTimeImmutable(sprintf('2026-08-%02dT%02d:00:00-07:00', $day, $hour));
        return new TimeSlot($start, $start->modify('+1 hour'));
    }

    public function testOnlineMeetingsShareTheCalendarButDoNotReserveOrConflictWithRooms(): void
    {
        [$f, $section] = $this->school();
        $events = $f->sql->rows('sis_history_events');
        $service = $this->service($f);
        $first = $service->request(null, 'Fictional online seminar', [$this->time()], section: $section);
        $second = $service->request(null, 'Fictional online breakout', [$this->time()], section: $section);
        $service->approve($first->id, 0);
        $service->approve($second->id, 0);
        $stored = new SqlBookingRepository($f->sql)->get($first->id);
        self::assertNull($stored->roomId);
        self::assertNotNull($stored->sectionPublicationId);
        self::assertSame('approved', $stored->status);
        $calendar = $this->calendar($f)->timetable('2026-08-10', location: 'online');
        self::assertCount(2, $calendar['items']);
        self::assertSame(['approved' => 2, 'requested' => 0], $calendar['totals']);
        self::assertNull($calendar['items'][0]['room_id']);
        self::assertNull($calendar['items'][0]['room_code']);
        self::assertSame($section->value, $calendar['items'][0]['section_id']);
        self::assertSame([], $this->calendar($f)->timetable('2026-08-10', location: 'room')['items']);
        self::assertSame([], $this->calendar($f)->timetable('2026-08-10', building: $f->building())['items']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
    }

    public function testOnlineRecurringRetriesAndCancellationsRetainPublicationAndOccurrenceIdentities(): void
    {
        [$f, $section] = $this->school();
        $service = $this->service($f);
        $pattern = new BookingPattern(
            '2026-08-10',
            '2026-08-12',
            '10:00',
            '11:00',
            [1, 3],
            1,
            [],
            false,
            'America/Los_Angeles'
        );
        $review = $service->review(null, 'Fictional online classes', $pattern, section: $section);
        self::assertNull($review['room']);
        self::assertSame(0, $review['conflicts']);
        $id = EntityId::generate();
        $saved = $service->requestSeries(
            $id,
            null,
            'Fictional online classes',
            $pattern,
            $review['revision'],
            section: $section
        );
        $service->approve($id, 0);
        $service->cancelOccurrence($id, $saved->occurrences[0]->id, 1);
        $f->sql->update('sis_sections', ['active' => 0], ['id' => $section->value]);
        $retry = $service->requestSeries(
            $id,
            null,
            'Fictional online classes',
            $pattern,
            $review['revision'],
            section: $section
        );
        self::assertSame(2, $retry->version);
        self::assertSame($saved->occurrences[0]->id->value, $retry->occurrences[0]->id->value);
        self::assertNotNull($retry->occurrences[0]->cancelledAt);
        self::assertSame($saved->sectionPublicationId->value, $retry->sectionPublicationId->value);
        self::assertCount(1, $this->calendar($f)->timetable('2026-08-10', location: 'online')['items']);
        $service->cancel($id, 2);
        self::assertSame([], $this->calendar($f)->timetable('2026-08-10', location: 'online')['items']);
        self::assertCount(2, $f->sql->rows('sis_booking_slots', ['booking_id' => $id->value]));
    }

    public function testHybridReschedulingMovesBetweenRoomsAndOnlineWhilePreservingHistoryAndCheckingConflicts(): void
    {
        [$f, $section] = $this->school(DeliveryMode::Hybrid);
        $service = $this->service($f);
        $room = $f->room('Fictional hybrid classroom', 30);
        $events = $f->sql->rows('sis_history_events');
        $physical = $service->request($room, 'Fictional hybrid class', [$this->time()], section: $section);
        $approved = $service->approve($physical->id, 0);
        $review = $service->reviewReplacement($approved->id, $approved->occurrences[0]->id, null, $this->time(), 1);
        self::assertNull($review['room']);
        self::assertTrue($review['available']);
        $online = $service->replaceOccurrence(
            $approved->id,
            $approved->occurrences[0]->id,
            new EntityId($review['new_id']),
            null,
            $this->time(),
            1,
            $review['revision']
        );
        self::assertNull($online->roomId);
        self::assertSame('approved', $online->status);
        self::assertSame($physical->sectionPublicationId->value, $online->sectionPublicationId->value);
        $back = $service->reviewReplacement($online->id, $online->occurrences[0]->id, $room, $this->time(11), 1);
        $blocker = $service->request($room, 'Fictional conflicting booking', [$this->time(11)]);
        $service->approve($blocker->id, 0);
        try {
            $service->replaceOccurrence(
                $online->id,
                $online->occurrences[0]->id,
                new EntityId($back['new_id']),
                $room,
                $this->time(11),
                1,
                $back['revision']
            );
            self::fail('A room conflict replaced an online meeting.');
        } catch (ConcurrentModificationException) {
            self::assertNull($service->get($online->id)->occurrences[0]->cancelledAt);
        }
        $back = $service->reviewReplacement($online->id, $online->occurrences[0]->id, $room, $this->time(12), 1);
        $replacement = $service->replaceOccurrence(
            $online->id,
            $online->occurrences[0]->id,
            new EntityId($back['new_id']),
            $room,
            $this->time(12),
            1,
            $back['revision']
        );
        self::assertSame($room->value, $replacement->roomId->value);
        self::assertSame($physical->ownerUserId->value, $replacement->ownerUserId->value);
        self::assertSame($physical->sectionPublicationId->value, $replacement->sectionPublicationId->value);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testUnlinkedInPersonOutOfDatesAndUnauthorizedOnlineRequestsAreRejected(): void
    {
        [$f, $section] = $this->school(DeliveryMode::InPerson);
        $service = $this->service($f);
        foreach (
            [fn() => $service->request(null, 'Unlinked', [$this->time()]),
            fn() => $service->request(null, 'In person', [$this->time()], section: $section)] as $request
        ) {
            try {
                $request();
                self::fail('An invalid online location was accepted.');
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows('sis_bookings'));
            }
        }
        [$f, $section] = $this->school();
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $this->service($f)->request(null, 'Denied online meeting', [$this->time()], section: $section);
    }

    public function testRevokedAuthorityDuringApprovalRollsBackTheOnlineDecision(): void
    {
        [$f, $section] = $this->school();
        $booking = $this->service($f)->request(null, 'Fictional online meeting', [$this->time()], section: $section);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            $f->access->staff = false;
        });
        try {
            $this->service($f, $audit)->approve($booking->id, 0);
            self::fail('Revoked scheduling authority approved a meeting.');
        } catch (AccessDeniedException) {
            $stored = new SqlBookingRepository($f->sql)->get($booking->id);
            self::assertSame('requested', $stored->status);
            self::assertSame(0, $stored->version);
        }
    }

    public function testOnlineFilterRunsBeforeTheResultBound(): void
    {
        [$f, $section] = $this->school();
        $room = $f->room('Fictional busy room', 30);
        $times = [];
        $start = new DateTimeImmutable('2026-08-10T00:00:00-07:00');
        for ($i = 0; $i < 500; ++$i) {
            $at = $start->modify('+' . $i . ' minutes');
            $times[] = new TimeSlot($at, $at->modify('+30 seconds'));
        }
        foreach (array_chunk($times, 250) as $chunk) {
            new SqlBookingRepository($f->sql)->add(Booking::request(
                EntityId::generate(),
                $room,
                $f->access->actor,
                'Fictional room meetings',
                $chunk
            ));
        }
        $online = $this->service($f)->request(null, 'Fictional online meeting', [$this->time()], section: $section);
        try {
            $this->calendar($f)->timetable('2026-08-10');
            self::fail('A partial mixed calendar was returned.');
        } catch (ValidationException) {
            $result = $this->calendar($f)->timetable('2026-08-10', location: 'online');
            self::assertSame([$online->id->value], array_column($result['items'], 'booking_id'));
        }
    }
}
