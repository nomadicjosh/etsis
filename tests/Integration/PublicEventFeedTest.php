<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\Http\Controller\CampusEventFeedController;
use Application\Service\PublicEventFeedSettings;
use Application\View\PublicEventCalendar;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\Service\PublicEventService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlPublicEventRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use Qubus\Http\ServerRequest;
use SisFixture;

final class PublicEventFeedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function fixture(): array
    {
        $f = new SisFixture();
        $f->clock->time = new DateTimeImmutable('2026-08-10T10:00:00', new DateTimeZone('America/Los_Angeles'));
        $service = new PublicEventService(
            new SqlPublicEventRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock
        );
        return [$f, $service];
    }

    private function booking(SisFixture $f, array $starts, bool $online = false): Booking
    {
        $slots = array_map(static function (string $start): BookingOccurrence {
            $at = new DateTimeImmutable($start);
            return BookingOccurrence::plan(new TimeSlot($at, $at->modify('+1 minute')));
        }, $starts);
        $booking = new Booking(
            EntityId::generate(),
            $online ? null : $f->room('PUBLIC', 30),
            $f->access->actor,
            'PRIVATE purpose and joining credential',
            $slots,
            'approved'
        );
        new SqlBookingRepository($f->sql)->add($booking);
        return $booking;
    }

    private function publish(PublicEventService $service, Booking $booking, string $title = 'Public invitation'): array
    {
        $review = $service->review($booking->id, $title, 'Explicit public description.');
        return $service->publish($booking->id, $review['review_token']);
    }

    private function controller(PublicEventService $service, mixed $enabled = true): CampusEventFeedController
    {
        $config = Collection::factory([]);
        $config->setConfigKey('portal', ['public_event_feed' => ['enabled' => $enabled]]);
        $config->setConfigKey('app', ['url' => 'https://campus.example.invalid']);
        return new CampusEventFeedController(new PublicEventFeedSettings($config), $service, new PublicEventCalendar());
    }

    private function request(string $method = 'GET'): ServerRequest
    {
        return new ServerRequest([], [], 'https://PRIVATE.invalid/portal/events/calendar.ics?search=PRIVATE', $method)
            ->withHeader('X-Forwarded-Host', 'PRIVATE.invalid');
    }

    public function testGuestFeedIncludesOnlyPublishedValuesAndLeavesEveryExistingFactUntouched(): void
    {
        [$f, $service] = $this->fixture();
        $booking = $this->booking($f, ['2026-08-11T01:30:00Z']);
        self::assertSame([], $service->calendarFeed());
        $this->publish($service, $booking);
        $tables = ['sis_bookings', 'sis_booking_slots', 'sis_public_events', 'sis_public_event_times',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit'];
        $facts = array_map($f->sql->rows(...), $tables);
        $f->access->staff = false;
        $entries = $service->calendarFeed();
        $fields = ['uid', 'version', 'published_at', 'starts_at', 'ends_at', 'title', 'description',
            'room_label', 'building_label', 'calendar_day'];
        self::assertEqualsCanonicalizing($fields, array_keys($entries[0]));
        self::assertSame('2026-08-10', $entries[0]['calendar_day']);
        $response = $this->controller($service)->feed($this->request());
        $body = str_replace("\r\n ", '', (string) $response->getBody());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/calendar; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('date=2026-08-10&day=2026-08-10', $body);
        self::assertStringNotContainsString('PRIVATE', $body);
        foreach ([$booking->id->value, $booking->ownerUserId->value, $booking->occurrences[0]->id->value] as $id) {
            self::assertStringNotContainsString($id, $body);
        }
        self::assertSame($facts, array_map($f->sql->rows(...), $tables));
    }

    public function testRepublicationKeepsMeetingUidsWhileChangingSequenceAndPublicText(): void
    {
        [$f, $service] = $this->fixture();
        $booking = $this->booking($f, ['2026-08-10T18:00:00Z', '2026-08-11T18:00:00Z'], true);
        $first = $this->publish($service, $booking);
        $entries = $service->calendarFeed();
        $projectionIds = array_column($f->sql->rows('sis_public_event_times'), 'id');
        $this->publish($service, $booking, 'Revised public invitation');
        self::assertNotSame($projectionIds, array_column($f->sql->rows('sis_public_event_times'), 'id'));
        $next = $service->calendarFeed();
        self::assertSame(array_column($entries, 'uid'), array_column($next, 'uid'));
        self::assertSame([2, 2], array_column($next, 'version'));
        self::assertSame('Online event', $next[0]['room_label']);
        self::assertSame('Revised public invitation', $next[0]['title']);
        $service->withdraw($booking->id, 2);
        self::assertSame([], $service->calendarFeed());
        self::assertNotEmpty($f->sql->rows('sis_public_events'));
        self::assertSame($first['id'], $f->sql->rows('sis_public_events')[0]['id']);
    }

    public function testCancelledAndMismatchedMeetingsDisappearWithoutRewritingPublicHistory(): void
    {
        [$f, $service] = $this->fixture();
        $booking = $this->booking($f, ['2026-08-10T18:00:00Z', '2026-08-11T18:00:00Z']);
        $this->publish($service, $booking);
        $before = $f->sql->rows('sis_public_event_times');
        $cancelled = $booking->cancelOccurrence($booking->occurrences[0]->id, $f->access->actor, $f->clock->now());
        new SqlBookingRepository($f->sql)->cancelOccurrence($cancelled, $booking->occurrences[0]->id, 0);
        self::assertCount(1, $service->calendarFeed());
        $f->sql->update(
            'sis_booking_slots',
            ['room_id' => $f->room('OTHER', 10)->value],
            ['id' => $booking->occurrences[1]->id->value]
        );
        self::assertSame([], $service->calendarFeed());
        self::assertSame($before, $f->sql->rows('sis_public_event_times'));
        $slot = $booking->occurrences[1];
        $f->sql->update(
            'sis_booking_slots',
            ['room_id' => $booking->roomId->value,
            'starts_at' => $slot->time->startsAt->modify('+30 seconds')->format('Y-m-d H:i:s')],
            ['id' => $slot->id->value]
        );
        self::assertSame([], $service->calendarFeed());
        $f->sql->update(
            'sis_booking_slots',
            ['starts_at' => $slot->time->startsAt->format('Y-m-d H:i:s')],
            ['id' => $slot->id->value]
        );
        self::assertCount(1, $service->calendarFeed());
        new SqlBookingRepository($f->sql)->save($cancelled->transition('cancelled'), 1);
        self::assertSame([], $service->calendarFeed());
    }

    public function testAnEarlierPublicProjectionCannotExposeALaterClassLinkedSource(): void
    {
        require_once dirname(__DIR__) . '/Support/AcademicTimetableFixture.php';
        $school = new \Tests\Support\AcademicTimetableFixture();
        $f = $school->sis;
        $f->clock->time = new DateTimeImmutable('2026-08-10T10:00:00-07:00');
        $service = new PublicEventService(
            new SqlPublicEventRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock
        );
        $private = $school->bookings->request(null, 'Private online class', [
            new TimeSlot(
                new DateTimeImmutable('2026-08-12T10:00:00-07:00'),
                new DateTimeImmutable('2026-08-12T11:00:00-07:00')
            ),
        ], section: $school->section->id);
        $booking = $this->booking($f, ['2026-08-10T18:00:00Z'], true);
        $this->publish($service, $booking);
        $source = $f->sql->one('sis_bookings', ['id' => $private->id->value]);
        $f->sql->update(
            'sis_bookings',
            ['section_publication_id' => $source['section_publication_id']],
            ['id' => $booking->id->value]
        );
        self::assertSame([], $service->calendarFeed());
        self::assertCount(1, $f->sql->rows('sis_public_events'));
    }

    public function testTheRollingCivilWindowExcludesBothNonOverlappingBoundaries(): void
    {
        [$f, $service] = $this->fixture();
        $booking = $this->booking($f, ['2026-08-03T06:59:00Z', '2026-08-03T07:00:00Z',
            '2026-11-08T07:59:00Z', '2026-11-08T08:00:00Z'], true);
        $this->publish($service, $booking);
        self::assertSame(
            ['2026-08-03T07:00:00Z', '2026-11-08T07:59:00Z'],
            array_column($service->calendarFeed(), 'starts_at')
        );
    }

    public function testDisabledMalformedAndHeadResponsesArePrivateAndDoNotExposeRequestValues(): void
    {
        [$f, $service] = $this->fixture();
        self::assertSame(404, $this->controller($service, false)->feed($this->request())->getStatusCode());
        $malformed = $this->controller($service, 'PRIVATE malformed')->feed($this->request());
        self::assertSame(503, $malformed->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $malformed->getBody());
        $head = $this->controller($service)->feed($this->request('HEAD'));
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
        self::assertGreaterThan(0, (int) $head->getHeaderLine('Content-Length'));
        self::assertSame([], $f->sql->rows('sis_audit'));
    }

    public function testMoreThan500TimesReturnsACompleteRetryResponseInsteadOfAPartialFeed(): void
    {
        [$f, $service] = $this->fixture();
        $at = new DateTimeImmutable('2026-08-10T08:00:00Z');
        foreach ([366, 134] as $count) {
            $starts = [];
            for ($i = 0; $i < $count; $i++) {
                $starts[] = $at->modify('+' . ($i * 2) . ' minutes')->format(DATE_ATOM);
            }
            $this->publish($service, $this->booking($f, $starts, true));
        }
        $full = $this->controller($service)->feed($this->request());
        self::assertSame(200, $full->getStatusCode());
        self::assertSame(500, preg_match_all('/^BEGIN:VEVENT\r$/m', (string) $full->getBody()));
        $this->publish($service, $this->booking($f, ['2026-08-12T10:00:00Z'], true));
        $response = $this->controller($service)->feed($this->request());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->getHeaderLine('Retry-After'));
        self::assertStringNotContainsString('BEGIN:VCALENDAR', (string) $response->getBody());
        self::assertCount(501, $f->sql->rows('sis_public_event_times'));
    }
}
