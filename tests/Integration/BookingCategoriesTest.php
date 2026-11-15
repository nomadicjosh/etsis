<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\Service\BookingCalendarService;
use Domain\Scheduling\Service\BookingCategoryService;
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

final class BookingCategoriesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    private function categories(SisFixture $f, ?AuditTrail $audit = null): BookingCategoryService
    {
        return new BookingCategoryService(
            new SqlBookingCategoryRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    private function bookings(SisFixture $f, ?AuditTrail $audit = null): BookingService
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

    private function time(string $day = '2026-08-10'): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable($day . 'T10:00:00-07:00'),
            new DateTimeImmutable($day . 'T11:00:00-07:00')
        );
    }

    private function pattern(): BookingPattern
    {
        return new BookingPattern(
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
    }

    public function testCategoryEditingRetirementAndCalendarFiltersPreserveExistingBookingsAndEvents(): void
    {
        $f = new SisFixture();
        $categories = $this->categories($f);
        $course = $categories->create('COURSE', 'Course sections', 'blue');
        $meeting = $categories->create('MEETING', 'Meetings', 'teal');
        $room = $f->room('Fictional.101', 30);
        $service = $this->bookings($f);
        $booked = $service->request($room, 'Fictional class', [$this->time()], $course);
        $service->request($room, 'Fictional office meeting', [$this->time('2026-08-11')], $meeting);
        $before = $f->sql->rows('sis_history_events');
        $categories->edit($course, 'COURSE', 'Classes', 'purple', false, 0);
        $result = $this->calendar($f)->timetable('2026-08-10', category: $course);
        self::assertSame([$booked->id->value], array_column($result['items'], 'booking_id'));
        self::assertSame('Classes', $result['items'][0]['category_name']);
        self::assertSame('purple', $result['items'][0]['category_tone']);
        self::assertFalse($result['category']['active']);
        self::assertSame('Classes', $service->view($booked->id)['category']['name']);
        self::assertCount(1, $this->calendar($f)->timetable('2026-08-10', search: 'Classes')['items']);
        try {
            $service->request($room, 'Fictional new request', [$this->time()], $course);
            self::fail('A retired category was assigned.');
        } catch (ValidationException $error) {
            self::assertSame('category_id', $error->errors[0]['field']);
        }
        $categories->edit($course, 'COURSE', 'Classes', 'purple', true, 1);
        try {
            $categories->edit($course, 'OTHER', 'Stale edit', 'amber', false, 1);
            self::fail('A stale category edit succeeded.');
        } catch (ConcurrentModificationException) {
            self::assertTrue($categories->view($course)['active']);
        }
        $changed = $service->categorize($booked->id, $meeting, 0);
        self::assertSame(1, $changed->version);
        self::assertSame($meeting->value, $changed->categoryId->value);
        self::assertSame([], $this->calendar($f)->timetable('2026-08-10', category: $course)['items']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
    }

    public function testReviewedRequestsBindCategoryChangesAndAcceptedRetriesKeepTheirOriginalClassification(): void
    {
        $f = new SisFixture();
        $f->clock->time = $f->clock->time->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $categories = $this->categories($f);
        $category = $categories->create('MEETING', 'Meetings', 'teal');
        $room = $f->room('Fictional.102', 30);
        $bookings = $this->bookings($f);
        $pattern = $this->pattern();
        $review = $bookings->review($room, 'Fictional recurring meetings', $pattern, $category);
        $categories->edit($category, 'MEETING', 'Team meetings', 'teal', true, 0);
        try {
            $bookings->requestSeries(
                EntityId::generate(),
                $room,
                'Fictional recurring meetings',
                $pattern,
                $review['revision'],
                $category
            );
            self::fail('A stale classification review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_bookings'));
        }
        $review = $bookings->review($room, 'Fictional recurring meetings', $pattern, $category);
        $id = EntityId::generate();
        $accepted = $bookings->requestSeries(
            $id,
            $room,
            'Fictional recurring meetings',
            $pattern,
            $review['revision'],
            $category
        );
        $bookings->categorize($id, null, 0);
        $categories->edit($category, 'MEETING', 'Team meetings', 'teal', false, 1);
        $retry = $bookings->requestSeries(
            $id,
            $room,
            'Fictional recurring meetings',
            $pattern,
            $review['revision'],
            $category
        );
        self::assertNull($retry->categoryId);
        self::assertSame(1, $retry->version);
        self::assertCount(2, $retry->occurrences);
        self::assertSame($accepted->id->value, $retry->id->value);
        $this->expectException(ConcurrentModificationException::class);
        $bookings->requestSeries($id, $room, 'Fictional recurring meetings', $pattern, $review['revision'], null);
    }

    public function testUnauthorizedInvalidDuplicateAndFailedAuditWritesDoNotLeavePartialData(): void
    {
        $f = new SisFixture();
        $categories = $this->categories($f);
        $categories->create('MEETING', 'Meetings', 'blue');
        foreach ([['MEETING', 'Another name', 'blue'], ['BAD', 'Invalid color', 'url(x)']] as $input) {
            try {
                $categories->create(...$input);
                self::fail('An invalid or duplicated category was saved.');
            } catch (ValidationException) {
                self::assertCount(1, $f->sql->rows('sis_booking_categories'));
            }
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit failure'));
        try {
            $this->categories($f, $audit)->create('CONFERENCE', 'Conference', 'amber');
            self::fail('A failed required audit committed.');
        } catch (\RuntimeException) {
            self::assertCount(1, $f->sql->rows('sis_booking_categories'));
        }
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $categories->create('DENIED', 'Denied', 'blue');
    }

    public function testActualSectionMeetingsRetainPublishedContextAndPreserveItThroughRescheduling(): void
    {
        $c = new \CatalogWorkflowFixture();
        $f = $c->sis;
        $f->clock->time = $f->clock->time->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $offerings = $c->offerings();
        $plan = $c->plan();
        $review = $offerings->review($plan);
        $operation = new EntityId($review['operation_id']);
        $offerings->save($operation, $plan, $review['revision']);
        $publication = $c->publicationReview($offerings, $operation);
        $offerings->publish($operation, $publication['review_token']);
        $section = $plan->sections[0]->id;
        $service = $this->bookings($f);
        $context = $service->sectionContext($section);
        $events = $f->sql->rows('sis_history_events');
        $category = $this->categories($f)->create('COURSE', 'Course sections', 'blue');
        $room = $f->room('Fictional lecture room', 40);
        $pattern = $this->pattern();
        $review = $service->review($room, 'Fictional class meetings', $pattern, $category, $section);
        self::assertSame($context, $review['section']);
        $id = EntityId::generate();
        $booked = $service->requestSeries(
            $id,
            $room,
            'Fictional class meetings',
            $pattern,
            $review['revision'],
            $category,
            $section
        );
        self::assertSame($context['publication_id'], $booked->sectionPublicationId->value);
        $calendar = $this->calendar($f)->timetable('2026-08-10', search: $context['code'], category: $category);
        self::assertCount(2, $calendar['items']);
        self::assertSame($section->value, $calendar['items'][0]['section_id']);
        self::assertSame($context['term_code'], $calendar['items'][0]['section_term_code']);
        $later = $c->sectionPublications()->review(
            $section,
            '2026-08-15',
            '2026-12-01',
            'Fictional later offering publication'
        );
        $c->sectionPublications()->publish($section, $later['review_token']);
        $events = $f->sql->rows('sis_history_events');
        self::assertSame($context, $service->view($id)['section']);
        $approved = $service->approve($id, 0);
        $occurrence = $approved->occurrences[0]->id;
        $move = $this->time('2026-08-11');
        $moveReview = $service->reviewReplacement($id, $occurrence, $room, $move, 1);
        $replacement = $service->replaceOccurrence(
            $id,
            $occurrence,
            new EntityId($moveReview['new_id']),
            $room,
            $move,
            1,
            $moveReview['revision']
        );
        self::assertSame($category->value, $replacement->categoryId->value);
        self::assertSame($context['publication_id'], $replacement->sectionPublicationId->value);
        self::assertSame($context, $service->view($replacement->id)['section']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
        $this->expectException(ValidationException::class);
        $service->request($room, 'Outside instruction', [$this->time('2027-01-10')], $category, $section);
    }

    public function testCategoryFilterAppliesBeforeTheCalendarLimit(): void
    {
        $f = new SisFixture();
        $category = $this->categories($f)->create('QUIET', 'Quiet meetings', 'gray');
        $room = $f->room('Fictional dense room', 30);
        $start = new DateTimeImmutable('2026-08-10T00:00:00-07:00');
        $times = [];
        for ($i = 0; $i < 500; ++$i) {
            $at = $start->modify('+' . $i . ' minutes');
            $times[] = new TimeSlot($at, $at->modify('+30 seconds'));
        }
        foreach (array_chunk($times, 250) as $chunk) {
            $this->bookings($f)->request($room, 'Fictional busy schedule', $chunk);
        }
        $quiet = $this->bookings($f)->request($room, 'Fictional quiet meeting', [$this->time()], $category);
        try {
            $this->calendar($f)->timetable('2026-08-10');
            self::fail('A partial calendar was returned.');
        } catch (ValidationException) {
            $result = $this->calendar($f)->timetable('2026-08-10', category: $category);
            self::assertSame([$quiet->id->value], array_column($result['items'], 'booking_id'));
            self::assertSame(['approved' => 0, 'requested' => 1], $result['totals']);
        }
    }

    public function testUnpublishedSectionsCannotBecomeClassMeetingsByCategoryAlone(): void
    {
        $f = new SisFixture();
        $section = $f->section();
        $room = $f->room('Fictional.103', 30);
        try {
            $this->bookings($f)->request($room, 'Fictional class', [$this->time()], section: $section->id);
            self::fail('An unpublished section was linked.');
        } catch (ValidationException $error) {
            self::assertSame('section_id', $error->errors[0]['field']);
            self::assertSame([], $f->sql->rows('sis_bookings'));
        }
    }
}
