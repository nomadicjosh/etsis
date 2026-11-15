<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\AcademicTimetableFixture;

final class AcademicTimetableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/AcademicTimetableFixture.php';
    }

    private function time(
        string $start = '2026-08-10T10:00:00-07:00',
        string $end = '2026-08-10T11:00:00-07:00'
    ): TimeSlot {
        return new TimeSlot(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function testOwnerSeesPublishedHybridTimesOnceWhileLearningAndTeachingWithoutStaffPrivileges(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        $room = $f->room('LIB.101', 40);
        foreach ([$room, null] as $place) {
            $booking = $school->bookings->request(
                $place,
                'Private fictional meeting purpose',
                [$this->time()],
                section: $school->section->id
            );
            $school->bookings->approve($booking->id, 0);
        }
        $school->publish();
        $before = $f->sql->rows('sis_history_events');
        $f->access->staff = false;
        $f->access->grants = [];
        $context = $school->service()->context();
        self::assertSame($f->term->value, $context['recommended_term']['id']);
        $result = $school->service()->timetable($f->term);
        self::assertCount(2, $result['items']);
        self::assertCount(1, $result['classes']);
        self::assertTrue($result['classes'][0]['learning']);
        self::assertTrue($result['classes'][0]['teaching']);
        self::assertSame(['published' => 2], $result['totals']);
        self::assertNull($result['items'][1]['room_code']);
        self::assertSame('LIB.101', $result['items'][0]['room_code']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['Private fictional', 'protected publication reason', 'owner_user_id', 'booking_id', 'actor_id',
            'student_id', 'review_token', 'faculty_user_id', 'email'] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame($before, $f->sql->rows('sis_history_events'));
    }

    public function testUnpublishedAndExplicitlyUnscheduledClassesRemainDistinct(): void
    {
        $school = new AcademicTimetableFixture();
        $initial = $school->service()->timetable($school->sis->term);
        self::assertSame('unpublished', $initial['classes'][0]['schedule_status']);
        self::assertSame([], $initial['items']);
        $school->publish();
        $after = $school->service()->timetable($school->sis->term);
        self::assertSame('unscheduled', $after['classes'][0]['schedule_status']);
        self::assertSame([], $after['items']);
    }

    public function testAdminAuthorityDoesNotGrantAnotherPersonsPersonalTimetable(): void
    {
        $school = new AcademicTimetableFixture();
        $school->sis->access->actor = $school->sis->user();
        self::assertSame([], $school->service()->terms()['items']);
        self::assertNull($school->service()->context()['recommended_term']);
        $this->expectException(AccessDeniedException::class);
        $school->service()->timetable($school->sis->term);
    }

    public function testDroppingAndReassigningTeachingRemovesBothCurrentRelationships(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        $f->sql->update(
            'sis_sections',
            ['faculty_user_id' => $f->user()->value],
            ['id' => $school->section->id->value]
        );
        self::assertFalse($school->service()->timetable($f->term)['classes'][0]['teaching']);
        $f->service->drop($school->student->id, $school->section->id);
        self::assertSame([], $school->service()->context()['terms']);
        $this->expectException(AccessDeniedException::class);
        $school->service()->timetable($f->term);
    }

    public function testWorkingCancellationsAndCurrentRenamesCannotChangeAPublishedTimetable(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        $room = $f->room('SCI.101', 40);
        $booking = $school->bookings->request(
            $room,
            'Private fictional class',
            [$this->time()],
            section: $school->section->id
        );
        $school->bookings->approve($booking->id, 0);
        $school->publish();
        $before = $school->service()->timetable($f->term)['items'];
        $f->sql->update('sis_rooms', ['name' => 'New room label'], ['id' => $room->value]);
        $f->sql->update(
            'sis_courses',
            ['title' => 'A later course title'],
            ['id' => $school->section->courseId->value]
        );
        $school->bookings->cancel($booking->id, 1);
        self::assertSame($before, $school->service()->timetable($f->term)['items']);
        $school->publish();
        self::assertSame([], $school->service()->timetable($f->term)['items']);
    }

    public function testTermChoicesAreOwnerScopedPagedAndTreatWildcardsAsLiteralText(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        for ($i = 0; $i < 22; $i++) {
            $term = EntityId::generate();
            $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => 'T' . $i, 'starts_on' => '2027-01-01',
                'ends_on' => '2027-03-01', 'add_drop_ends_on' => '2027-01-10']);
            $section = $f->section(faculty: $f->access->actor);
            $f->sql->update('sis_sections', ['term_id' => $term->value], ['id' => $section->id->value]);
        }
        $first = $school->service()->terms();
        self::assertCount(20, $first['items']);
        $second = $school->service()->terms(after: new EntityId($first['next_after']));
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], $school->service()->terms('%')['items']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
        self::assertSame($f->term->value, $school->service()->context()['recommended_term']['id']);
    }

    public function testPublishedReadsUseOnlyTheCurrentHeadAndRecheckMembershipAfterReading(): void
    {
        $school = new AcademicTimetableFixture();
        $school->publish();
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $history->expects(self::never())->method('append');
        $history->method('latest')->willReturnCallback($school->history->latest(...));
        $current = $school->service($history)->timetable($school->sis->term);
        self::assertSame('unscheduled', $current['classes'][0]['schedule_status']);
        $changed = $this->createMock(HistoricalEventStore::class);
        $changed->expects(self::never())->method('page');
        $changed->method('latest')->willReturnCallback(function (StreamReference $stream) use ($school) {
            $event = $school->history->latest($stream);
            $school->sis->sql->update(
                'sis_sections',
                ['faculty_user_id' => null],
                ['id' => $school->section->id->value]
            );
            return $event;
        });
        $this->expectException(ConcurrentModificationException::class);
        $school->service($changed)->timetable($school->sis->term);
    }

    public function testCorruptCurrentReferencesStopThePersonalRead(): void
    {
        $school = new AcademicTimetableFixture();
        $school->publish();
        $school->sis->sql->update(
            'sis_section_meeting_plans',
            ['definition_hash' => str_repeat('0', 64)],
            ['id' => $school->section->id->value]
        );
        $this->expectException(CorruptHistoryException::class);
        $school->service()->timetable($school->sis->term);
    }

    public function testMultiplePeriodsAndOvernightDaysShareTheInstitutionalCalendar(): void
    {
        $school = new AcademicTimetableFixture();
        $booking = $school->bookings->request(
            null,
            'Private overnight class',
            [$this->time('2026-08-09T23:00:00-07:00', '2026-08-10T01:00:00-07:00')],
            section: $school->section->id
        );
        $school->bookings->approve($booking->id, 0);
        $school->publish();
        $week = $school->service()->timetable($school->sis->term, '2026-08-10');
        self::assertSame(['published' => 1], $week['totals']);
        self::assertTrue($week['days'][0]['items'][0]['continues_before']);
        $month = $school->service()->timetable($school->sis->term, '2026-08-10', 'month');
        self::assertCount(31, $month['days']);
        self::assertTrue($month['days'][8]['items'][0]['continues_after']);
        self::assertSame([], $school->service()->timetable($school->sis->term, '2026-09-10')['items']);
    }

    public function testAnExcessiveClassSetNeverReturnsPartialCounts(): void
    {
        $school = new AcademicTimetableFixture();
        for ($i = 0; $i < 50; $i++) {
            $school->sis->section(faculty: $school->sis->access->actor);
        }
        $this->expectException(ValidationException::class);
        $school->service()->timetable($school->sis->term);
    }

    public function testFutureTermDefaultsAndSearchCannotBlendClassesFromOtherTerms(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '2027SP', 'starts_on' => '2027-01-04',
            'ends_on' => '2027-05-19', 'add_drop_ends_on' => '2027-01-10']);
        $section = $f->section(faculty: $f->access->actor);
        $f->sql->update('sis_sections', ['term_id' => $term->value], ['id' => $section->id->value]);
        $result = $school->service()->timetable($term);
        self::assertSame('2027-01-04', $result['from']);
        self::assertSame([$section->id->value], array_column($result['classes'], 'id'));
        self::assertSame([$term->value], array_column($school->service()->terms('2027SP')['items'], 'id'));
        $f->clock->time = $f->clock->time->modify('2026-12-25');
        self::assertSame($term->value, $school->service()->context()['recommended_term']['id']);
        $f->clock->time = $f->clock->time->modify('2028-01-01');
        self::assertSame($term->value, $school->service()->context()['recommended_term']['id']);
    }

    public function testSessionOwnerIsRecheckedBeforeReturningPersonalResults(): void
    {
        $school = new AcademicTimetableFixture();
        $access = $this->createStub(\Domain\Shared\Security\StudentAccess::class);
        $access->method('authenticatedUserId')->willReturnOnConsecutiveCalls(
            $school->sis->access->actor,
            EntityId::generate()
        );
        $this->expectException(AccessDeniedException::class);
        $school->service(access: $access)->timetable($school->sis->term);
    }

    public function testFiveHundredMeetingWeeksAreCompleteAndOverflowAcrossClassesFailsClosed(): void
    {
        $school = new AcademicTimetableFixture();
        $times = [];
        $start = new DateTimeImmutable('2026-08-10T08:00:00-07:00');
        for ($i = 0; $i < 250; $i++) {
            $instant = $start->modify('+' . $i . ' minutes');
            $times[] = new TimeSlot($instant, $instant->modify('+1 minute'));
        }
        for ($i = 0; $i < 2; $i++) {
            $booking = $school->bookings->request(
                null,
                'Private fictional series',
                $times,
                section: $school->section->id
            );
            $school->bookings->approve($booking->id, 0);
        }
        $school->publish();
        $result = $school->service()->timetable($school->sis->term);
        self::assertCount(500, $result['items']);
        self::assertSame(['published' => 500], $result['totals']);
        $another = $school->addSection();
        $extra = $school->bookings->request(null, 'Private additional meeting', [$this->time()], section: $another->id);
        $school->bookings->approve($extra->id, 0);
        $school->publish($another->id);
        $this->expectException(ValidationException::class);
        $school->service()->timetable($school->sis->term);
    }

    public function testOverlapAdviceUsesOnlyOwnedPublishedClassesAndAppendsNoDecisions(): void
    {
        $school = new AcademicTimetableFixture();
        $f = $school->sis;
        $another = $school->addSection();
        $sameClass = $school->bookings->request(
            null,
            'Private duplicate modality',
            [$this->time()],
            section: $school->section->id
        );
        $school->bookings->approve($sameClass->id, 0);
        $physical = $school->bookings->request(
            $f->room('Fictional.101', 40),
            'Private physical purpose',
            [$this->time()],
            section: $school->section->id
        );
        $school->bookings->approve($physical->id, 0);
        $other = $school->bookings->request(null, 'Private teaching purpose', [
            $this->time('2026-08-10T10:30:00-07:00', '2026-08-10T11:30:00-07:00'),
        ], section: $another->id);
        $school->bookings->approve($other->id, 0);
        $school->publish();
        // Approved working requests alone cannot become an academic overlap warning.
        self::assertSame(0, $school->service()->timetable($f->term)['overlaps']['pairs']);
        $school->publish($another->id);
        $events = $f->sql->rows('sis_history_events');
        $bookings = $f->sql->rows('sis_bookings');
        $f->access->staff = false;
        $f->access->grants = [];
        $result = $school->service()->timetable($f->term);
        self::assertSame(['pairs' => 2, 'meetings' => 3, 'sections' => 2], $result['overlaps']);
        self::assertSame([1, 1, 2], array_column($result['items'], 'overlap_count'));
        self::assertSame($result['overlaps'], $result['days'][0]['overlaps']);
        self::assertSame('2026-08-10T00:00:00-07:00', $result['days'][0]['starts_at']);
        self::assertSame('2026-08-11T00:00:00-07:00', $result['days'][0]['ends_at']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($bookings, $f->sql->rows('sis_bookings'));
        self::assertStringNotContainsString('Private', json_encode($result, JSON_THROW_ON_ERROR));
        $f->access->staff = true;
        $school->bookings->cancel($other->id, 1);
        self::assertSame(2, $school->service()->timetable($f->term)['overlaps']['pairs']);
        $school->publish($another->id);
        self::assertSame(0, $school->service()->timetable($f->term)['overlaps']['pairs']);
    }

    public function testOverlapAdviceDoesNotRevealAnotherInstructorsPublishedTimes(): void
    {
        $school = new AcademicTimetableFixture();
        $another = $school->addSection();
        foreach ([$school->section->id, $another->id] as $section) {
            $booking = $school->bookings->request(null, 'Private fictional class', [$this->time()], section: $section);
            $school->bookings->approve($booking->id, 0);
            $school->publish($section);
        }
        $f = $school->sis;
        $f->sql->update('sis_sections', ['faculty_user_id' => $f->user()->value], ['id' => $another->id->value]);
        $result = $school->service()->timetable($f->term);
        self::assertCount(1, $result['items']);
        self::assertSame(0, $result['overlaps']['pairs']);
        self::assertSame(0, $result['items'][0]['overlap_count']);
        self::assertSame(1, count($result['classes']));
    }

    public function testDailyOverlapCountsExcludeAnOvernightContinuationAfterItsOverlapEnds(): void
    {
        $school = new AcademicTimetableFixture();
        $another = $school->addSection();
        $a = $school->bookings->request(null, 'Private overnight session', [
            $this->time('2026-08-10T23:00:00-07:00', '2026-08-11T02:00:00-07:00'),
        ], section: $school->section->id);
        $b = $school->bookings->request(null, 'Private evening session', [
            $this->time('2026-08-10T22:30:00-07:00', '2026-08-10T23:30:00-07:00'),
        ], section: $another->id);
        $school->bookings->approve($a->id, 0);
        $school->bookings->approve($b->id, 0);
        $school->publish();
        $school->publish($another->id);
        $result = $school->service()->timetable($school->sis->term);
        self::assertSame(1, $result['overlaps']['pairs']);
        self::assertSame(1, $result['days'][0]['overlaps']['pairs']);
        self::assertSame(0, $result['days'][1]['overlaps']['pairs']);
        self::assertSame(0, $result['days'][1]['items'][0]['overlap_count']);
        self::assertTrue($result['days'][1]['items'][0]['continues_before']);
    }
}
