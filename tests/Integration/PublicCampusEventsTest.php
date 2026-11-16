<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\Repository\PublicEventRepository;
use Domain\Scheduling\Service\PublicEventService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlPublicEventRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PublicCampusEventsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function fixture(): SisFixture
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2026-08-10T10:00:00', new DateTimeZone('America/Los_Angeles'));
        return $f;
    }

    private function service(SisFixture $f, ?AuditTrail $audit = null): PublicEventService
    {
        return new PublicEventService(
            new SqlPublicEventRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock
        );
    }

    private function booking(
        SisFixture $f,
        string $status = 'approved',
        int $count = 1,
        bool $online = false
    ): Booking {
        $slots = [];
        for ($i = 0; $i < $count; ++$i) {
            $start = new DateTimeImmutable('2026-08-10T11:00:00-07:00')->modify('+' . $i . ' days');
            $slots[] = BookingOccurrence::plan(new TimeSlot($start, $start->modify('+1 hour')));
        }
        $booking = new Booking(
            EntityId::generate(),
            $online ? null : $f->room('PUBLIC.' . substr(EntityId::generate()->value, -6), 40),
            $f->access->actor,
            'PRIVATE STAFF PURPOSE: never public',
            $slots,
            $status
        );
        new SqlBookingRepository($f->sql)->add($booking);
        return $booking;
    }

    /** @return array<string,mixed> */
    private function publish(
        PublicEventService $service,
        Booking $booking,
        string $title = 'Fictional open house'
    ): array {
        $review = $service->review(
            $booking->id,
            $title,
            "Public invitation <script>example</script>\nEveryone is welcome."
        );
        $service->publish($booking->id, $review['review_token']);
        return $review;
    }

    public function testExplicitPublicationAndGuestReadsNeverExposeBookingPeopleOrPrivateDetails(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $before = $f->sql->rows('sis_history_events');
        self::assertSame([], $service->calendar()['items']);
        $setup = $service->setup($booking->id);
        self::assertNull($setup['current']);
        self::assertArrayNotHasKey('occurrence_id', $setup['preview']['times'][0]);
        $review = $this->publish($service, $booking);
        self::assertStringNotContainsString('PRIVATE', json_encode($review, JSON_THROW_ON_ERROR));
        $f->access->staff = false;
        $result = $service->calendar();
        self::assertCount(1, $result['items']);
        self::assertSame(1, $result['days'][9]['counts']['published']);
        self::assertSame('Fictional open house', $result['items'][0]['title']);
        self::assertNotSame($booking->id->value, $result['items'][0]['event_id']);
        self::assertNotSame($booking->occurrences[0]->id->value, $result['items'][0]['id']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['PRIVATE', 'booking_id', 'occurrence_id', 'actor_id', 'request_hash', 'operation_id',
            'review_token', $booking->id->value, $booking->ownerUserId->value] as $secret
        ) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertCount(1, $service->upcoming());
    }

    public function testBothPermissionsAreRequiredEvenForTheBookingsOwner(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $f->access->staff = false;
        foreach ([[], ['sis:scheduling:manage'], ['sis:portal:manage']] as $grants) {
            $f->access->grants = $grants;
            try {
                $this->service($f)->review($booking->id, 'Public title', 'Public description');
                self::fail('Both grants are necessary.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_public_events'));
            }
        }
        $f->access->grants = ['sis:scheduling:manage', 'sis:portal:manage'];
        self::assertNotEmpty($this->service($f)->setup($booking->id)['preview']);
    }

    public function testRequestedCancelledAndClassLinkedBookingsCannotBePublished(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        foreach (['requested', 'cancelled'] as $status) {
            $booking = $this->booking($f, $status);
            self::assertNull($service->setup($booking->id)['preview']);
            try {
                $service->review($booking->id, 'Public title', 'Public description');
                self::fail('Only approved meetings can be published.');
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows('sis_public_events'));
            }
        }
        require_once dirname(__DIR__) . '/Support/AcademicTimetableFixture.php';
        $school = new \Tests\Support\AcademicTimetableFixture();
        $booking = $school->bookings->request(null, 'Private online class', [
            new TimeSlot(
                new DateTimeImmutable('2026-08-10T10:00:00-07:00'),
                new DateTimeImmutable('2026-08-10T11:00:00-07:00')
            ),
        ], section: $school->section->id);
        $school->bookings->approve($booking->id, 0);
        $this->expectException(ValidationException::class);
        $this->service($school->sis)->review($booking->id, 'Public title', 'Public description');
    }

    public function testActorBoundReviewsExpireAndChangedRoomMetadataRequiresAnotherReview(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $review = $service->review($booking->id, 'Public title', 'Public description');
        $actor = $f->access->actor;
        $f->access->actor = $f->user();
        try {
            $service->publish($booking->id, $review['review_token']);
            self::fail('Another actor cannot confirm the review.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_public_events'));
        }
        $f->access->actor = $actor;
        $f->sql->update('sis_rooms', ['name' => 'Changed room label'], ['id' => $booking->roomId->value]);
        try {
            $service->publish($booking->id, $review['review_token']);
            self::fail('Location changes must be reviewed.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_public_events'));
        }
        $review = $service->review($booking->id, 'Public title', 'Public description');
        $f->clock->time = $f->clock->time->modify('+20 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $service->publish($booking->id, $review['review_token']);
    }

    public function testCancellationHidesOnlyThatPublishedOccurrenceAndKeepsOriginalFacts(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, count: 2);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $repo = new SqlBookingRepository($f->sql);
        $cancelled = $booking->cancelOccurrence($booking->occurrences[0]->id, $f->access->actor, $f->clock->now());
        $repo->cancelOccurrence($cancelled, $booking->occurrences[0]->id, 0);
        self::assertCount(2, $f->sql->rows('sis_public_event_times'));
        self::assertCount(1, $service->calendar()['items']);
        self::assertSame('2026-08-11T11:00:00-07:00', $service->calendar()['items'][0]['starts_at']);
        $repo->save($cancelled->transition('cancelled'), 1);
        self::assertSame([], $service->calendar()['items']);
        self::assertNull($service->setup($booking->id)['preview']);
        $service->withdraw($booking->id, 1);
        self::assertSame(0, $f->sql->one('sis_public_events', ['booking_id' => $booking->id->value])['published']);
    }

    public function testRetriesNeverRepublishWithdrawnContentOrOverwriteLaterEditorialChanges(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $review = $this->publish($service, $booking);
        $first = $service->publish($booking->id, $review['review_token']);
        $service->withdraw($booking->id, 1);
        $service->withdraw($booking->id, 1);
        self::assertSame($first, $service->publish($booking->id, $review['review_token']));
        self::assertSame([], $service->calendar()['items']);
        self::assertFalse($service->setup($booking->id)['current']['published']);
        $this->publish($service, $booking, 'Revised public title');
        self::assertSame('Revised public title', $service->calendar()['items'][0]['title']);
        self::assertSame($first['id'], $service->calendar()['items'][0]['event_id']);
        $this->expectException(ConcurrentModificationException::class);
        $service->publish($booking->id, $review['review_token']);
    }

    public function testAuditFailureRollsBackContentTimesAndPublication(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit failure'));
        $service = $this->service($f, $audit);
        $review = $service->review($booking->id, 'Public title', 'Public description');
        try {
            $service->publish($booking->id, $review['review_token']);
            self::fail('Required audit must commit with publication.');
        } catch (\RuntimeException) {
            self::assertSame([], $f->sql->rows('sis_public_events'));
            self::assertSame([], $f->sql->rows('sis_public_event_times'));
        }
    }

    public function testPublicCalendarFiltersBeforeItsBoundAndTreatsSearchWildcardsLiterally(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $this->publish($service, $booking, 'Open_100% house');
        self::assertCount(1, $service->calendar(search: '_100%')['items']);
        self::assertSame([], $service->calendar(search: 'Open!')['items']);
        self::assertSame([], $service->calendar('2026-09-01')['items']);
        // Build a dense public read model without inventing academic decisions or bypassing public source checks.
        $event = $f->sql->rows('sis_public_events')[0];
        for ($i = 0; $i < 500; ++$i) {
            $occurrence = EntityId::generate()->value;
            $f->sql->insert('sis_booking_slots', ['id' => $occurrence, 'booking_id' => $booking->id->value,
                'starts_at' => '2026-08-10 18:00:00', 'ends_at' => '2026-08-10 19:00:00',
                'room_id' => $booking->roomId->value]);
            $f->sql->insert('sis_public_event_times', ['id' => EntityId::generate()->value, 'event_id' => $event['id'],
                'occurrence_id' => $occurrence, 'starts_at' => '2026-08-10 18:00:00',
                'ends_at' => '2026-08-10 19:00:00']);
        }
        self::assertSame([], $service->calendar(search: 'No matching event')['items']);
        self::assertCount(3, $service->upcoming());
        $this->expectException(ValidationException::class);
        $service->calendar();
    }

    public function testAnEarlierEditorialReviewCannotReplaceAnAcceptedNewerOne(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $earlier = $service->review($booking->id, 'Earlier title', 'Earlier public details.');
        $this->publish($service, $booking, 'Accepted new title');
        try {
            $service->publish($booking->id, $earlier['review_token']);
            self::fail('A stale review must not overwrite the current event.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Accepted new title', $service->calendar()['items'][0]['title']);
        }
    }

    public function testRevokedPermissionAtCompletionRollsBackThePublicProjection(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            $f->access->staff = false;
            $f->access->grants = ['sis:scheduling:manage'];
        });
        $service = $this->service($f, $audit);
        $review = $service->review($booking->id, 'Public title', 'Public description');
        try {
            $service->publish($booking->id, $review['review_token']);
            self::fail('The content permission is required through completion.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_public_events'));
            self::assertSame([], $f->sql->rows('sis_public_event_times'));
        }
    }

    public function testReschedulingCannotSilentlyPublishTheReplacementOrRemoveOtherDates(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, count: 2);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $bookings = new \Domain\Scheduling\Service\BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Persistence\Repository\SqlRoomRepository($f->sql),
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
        $time = new TimeSlot(
            new DateTimeImmutable('2026-08-10T13:00:00-07:00'),
            new DateTimeImmutable('2026-08-10T14:00:00-07:00')
        );
        $review = $bookings->reviewReplacement($booking->id, $booking->occurrences[0]->id, $booking->roomId, $time, 0);
        $replacement = $bookings->replaceOccurrence(
            $booking->id,
            $booking->occurrences[0]->id,
            new EntityId($review['new_id']),
            $booking->roomId,
            $time,
            0,
            $review['revision']
        );
        $rows = $service->calendar()['items'];
        self::assertCount(1, $rows);
        self::assertSame('2026-08-11T11:00:00-07:00', $rows[0]['starts_at']);
        self::assertNull($service->setup($replacement->id)['current']);
        self::assertCount(2, $f->sql->rows('sis_public_event_times'));
    }

    public function testOnlinePublicEventsNeedNoRoomAndKeepSourcePurposeAndIdentitiesPrivate(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, count: 2, online: true);
        $service = $this->service($f);
        $events = $f->sql->rows('sis_history_events');
        $review = $this->publish($service, $booking, 'Fictional online gathering');
        self::assertNull($f->sql->one('sis_public_events', ['booking_id' => $booking->id->value])['room_id']);
        self::assertSame('Online event', $review['preview']['room_label']);
        self::assertSame('Joining details provided by the organizer', $review['preview']['building_label']);
        self::assertSame([], $f->sql->rows('sis_rooms'));
        $f->access->staff = false;
        $result = $service->calendar();
        self::assertCount(2, $result['items']);
        self::assertSame('Online event', $result['items'][0]['room_label']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'booking_id', 'occurrence_id', 'owner_user_id', $booking->id->value] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertCount(2, $service->upcoming());
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $f->access->staff = true;
        $repo = new SqlBookingRepository($f->sql);
        $changed = $booking->cancelOccurrence($booking->occurrences[0]->id, $f->access->actor, $f->clock->now());
        $repo->cancelOccurrence($changed, $booking->occurrences[0]->id, 0);
        self::assertCount(1, $service->calendar()['items']);
        $service->withdraw($booking->id, 1);
        self::assertSame([], $service->calendar()['items']);
        $service->publish($booking->id, $review['review_token']);
        self::assertSame([], $service->calendar()['items']);
    }

    public function testOnlineSourceLocationMismatchCannotBeReviewedOrReturnedAsPublicContent(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, online: true);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $room = $f->room('Fictional changed place', 40);
        $f->sql->update(
            'sis_booking_slots',
            ['room_id' => $room->value],
            ['id' => $booking->occurrences[0]->id->value]
        );
        self::assertSame([], $service->calendar()['items']);
        try {
            $service->review($booking->id, 'New public title', 'Public description');
            self::fail('A mismatched occurrence location was published.');
        } catch (ValidationException) {
            self::assertSame(1, $f->sql->rows('sis_public_events')[0]['version']);
        }
        $f->sql->update('sis_booking_slots', ['room_id' => null], ['id' => $booking->occurrences[0]->id->value]);
        self::assertCount(1, $service->calendar()['items']);
        $f->sql->update('sis_bookings', ['room_id' => $room->value], ['id' => $booking->id->value]);
        self::assertSame([], $service->calendar()['items']);
    }

    public function testAnnouncementUsesOnlyRetainedPublicContentAndCurrentlyVisibleMeetings(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, count: 3);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $f->sql->update('sis_rooms', ['name' => 'Unreviewed new location'], ['id' => $booking->roomId->value]);
        $repo = new SqlBookingRepository($f->sql);
        $repo->cancelOccurrence(
            $booking->cancelOccurrence($booking->occurrences[0]->id, $f->access->actor, $f->clock->now()),
            $booking->occurrences[0]->id,
            0
        );
        $f->sql->update(
            'sis_booking_slots',
            ['starts_at' => '2026-08-11 20:00:00'],
            ['id' => $booking->occurrences[1]->id->value]
        );
        $before = [];
        foreach (['sis_campaigns', 'sis_deliveries', 'sis_audit', 'sis_history_events'] as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $draft = $service->announcement($booking->id);
        self::assertSame(['subject', 'body'], array_keys($draft));
        self::assertSame('Fictional open house', $draft['subject']);
        self::assertStringContainsString('Public invitation <script>example</script>', $draft['body']);
        self::assertStringContainsString('America/Los_Angeles', $draft['body']);
        self::assertStringContainsString('Wed, Aug 12, 2026 11:00 -07:00', $draft['body']);
        foreach (
            ['Aug 10', 'Aug 11', 'PRIVATE', 'Unreviewed new location', $booking->id->value,
                $booking->ownerUserId->value, $booking->occurrences[2]->id->value] as $secret
        ) {
            self::assertStringNotContainsString($secret, $draft['body']);
        }
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
    }

    public function testAnnouncementIncludesTheWholePublishedSeriesAcrossMonthsAndTimezoneOffsets(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, count: 100, online: true);
        $service = $this->service($f);
        $this->publish($service, $booking, str_repeat('é', 191));
        $draft = $service->announcement($booking->id);
        self::assertSame(str_repeat('é', 191), $draft['subject']);
        self::assertSame(100, substr_count($draft['body'], ' through '));
        self::assertStringContainsString('Online event · Joining details provided by the organizer', $draft['body']);
        self::assertStringContainsString('Tue, Nov 17, 2026 10:00 -08:00', $draft['body']);
        self::assertLessThan(100000, strlen($draft['body']));
    }

    public function testUnpublishedWithdrawnCancelledAndMismatchedEventsCannotPrepareAnnouncements(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f, online: true);
        $service = $this->service($f);
        $reject = static function () use ($service, $booking): void {
            try {
                $service->announcement($booking->id);
                self::fail('An event without a current public meeting prepared an announcement.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        };
        $reject();
        $this->publish($service, $booking);
        $service->withdraw($booking->id, 1);
        $reject();
        $this->publish($service, $booking);
        foreach (['requested', 'cancelled'] as $status) {
            $f->sql->update('sis_bookings', ['status' => $status], ['id' => $booking->id->value]);
            $reject();
        }
        $f->sql->update('sis_bookings', ['status' => 'approved'], ['id' => $booking->id->value]);
        $f->sql->update(
            'sis_booking_slots',
            ['cancelled_at' => '2026-08-01 10:00:00'],
            ['id' => $booking->occurrences[0]->id->value]
        );
        $reject();
        $f->sql->update('sis_booking_slots', ['cancelled_at' => null,
            'room_id' => $f->room('PRIVATE new place', 10)->value], ['id' => $booking->occurrences[0]->id->value]);
        $reject();
    }

    public function testAnnouncementRequiresSchedulingPublicationAndCommunicationsPermissions(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $f->access->staff = false;
        $all = ['sis:scheduling:manage', 'sis:portal:manage', 'sis:communications:manage'];
        foreach ($all as $omitted) {
            $f->access->grants = array_values(array_diff($all, [$omitted]));
            try {
                $service->announcement($booking->id);
                self::fail('A required announcement permission was bypassed.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_campaigns'));
            }
        }
        $f->access->grants = $all;
        self::assertSame('Fictional open house', $service->announcement($booking->id)['subject']);
    }

    public function testAnnouncementRechecksAccessAfterThePublicReadAndRejectsTruncatedSeries(): void
    {
        $f = $this->fixture();
        $booking = $this->booking($f);
        $service = $this->service($f);
        $this->publish($service, $booking);
        $row = new SqlPublicEventRepository($f->sql)->publishedTimes($booking->id)[0];
        $repo = $this->createStub(PublicEventRepository::class);
        $repo->method('publishedTimes')->willReturnCallback(static function () use ($f, $row): array {
            $f->access->staff = false;
            return [$row];
        });
        $protected = new PublicEventService($repo, $f->access, $f->transactions, $f->audit, $f->dropCipher, $f->clock);
        try {
            $protected->announcement($booking->id);
            self::fail('A draft was returned after access was removed during its read.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_campaigns'));
        }
        $f->access->staff = true;
        $repo = $this->createStub(PublicEventRepository::class);
        $repo->method('publishedTimes')->willReturn(array_fill(0, 367, $row));
        $bounded = new PublicEventService($repo, $f->access, $f->transactions, $f->audit, $f->dropCipher, $f->clock);
        $this->expectException(ValidationException::class);
        $bounded->announcement($booking->id);
    }
}
