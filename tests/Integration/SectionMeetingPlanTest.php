<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlBookingCategoryRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;

final class SectionMeetingPlanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    /** @return array{CatalogWorkflowFixture, EntityId, BookingService} */
    private function school(): array
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $f->clock->time = $f->clock->time->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $p = $c->plan()->sections[0];
        $section = new Section(
            $p->id,
            $p->courseId,
            $p->termId,
            null,
            $p->code,
            30,
            false,
            true,
            $p->fee,
            DeliveryMode::Hybrid
        );
        $plan = new OfferingPlan($f->term, $section);
        $offerings = $c->offerings();
        $r = $offerings->review($plan);
        $operation = new EntityId($r['operation_id']);
        $offerings->save($operation, $plan, $r['revision']);
        $offerings->publish($operation, $c->publicationReview($offerings, $operation)['review_token']);
        $bookings = new BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            new SqlRoomRepository($f->sql),
            $f->clock,
            new SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
        return [$c, $section->id, $bookings];
    }

    private function plans(
        CatalogWorkflowFixture $c,
        ?AuditTrail $audit = null,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null,
    ): \Domain\Registration\Service\SectionMeetingPlanService {
        $f = $c->sis;
        return new \Domain\Registration\Service\SectionMeetingPlanService(
            new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository($f->sql, $f->bookingSections()),
            $history ?? new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $f->sql,
                $f->transactions,
                $f->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($f->connection->queryBuilder())
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $c->fixture->cipher,
            $f->clock,
        );
    }

    private function time(int $hour = 10): TimeSlot
    {
        $time = new DateTimeImmutable(sprintf('2026-08-10T%02d:00:00-07:00', $hour));
        return new TimeSlot($time, $time->modify('+1 hour'));
    }

    public function testReviewedHybridPublicationRetainsLocationsAndKeepsWorkingBookingsAsCrud(): void
    {
        [$c, $section, $bookings] = $this->school();
        $f = $c->sis;
        $original = $f->sql->rows('sis_history_events');
        $room = $f->room('LIB.101', 40);
        $physical = $bookings->request($room, 'Private fictional booking purpose', [$this->time()], section: $section);
        $online = $bookings->request(null, 'Private online purpose', [$this->time(12)], section: $section);
        $bookings->approve($physical->id, 0);
        $bookings->approve($online->id, 0);
        self::assertSame($original, $f->sql->rows('sis_history_events'));
        $service = $this->plans($c);
        self::assertNull($service->setup($section)['published']);
        $review = $service->review($section, 'Fictional registrar approved timetable');
        self::assertSame($original, $f->sql->rows('sis_history_events'));
        self::assertSame([], $f->sql->rows('sis_section_meeting_plans'));
        $receipt = $service->publish($section, $review['review_token']);
        self::assertSame(1, $receipt['last_version']);
        self::assertCount(count($original) + 1, $f->sql->rows('sis_history_events'));
        $setup = $service->setup($section);
        self::assertFalse($setup['changed']);
        $published = $setup['published'];
        self::assertSame($review['definition'], $published['definition']);
        self::assertCount(2, $published['definition']['meetings']);
        self::assertCount(1, $published['definition']['locations']);
        $wire = json_encode($setup, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private fictional', $wire);
        self::assertStringNotContainsString('Private online', $wire);
        self::assertStringNotContainsString('owner_user_id', $wire);
        self::assertStringNotContainsString('Fictional registrar', json_encode($f->sql->rows('sis_history_events')));
        $f->sql->update('sis_rooms', ['name' => 'Renamed later'], ['id' => $room->value]);
        $bookings->cancel($online->id, 1);
        self::assertTrue($service->setup($section)['changed']);
        self::assertSame($published, $service->setup($section)['published']);
        $second = $service->review($section, 'Fictional schedule correction');
        self::assertSame(2, $service->publish($section, $second['review_token'])['last_version']);
        self::assertSame('Renamed later', $service->setup($section)['published']['definition']['locations'][0]['name']);
        self::assertSame($receipt, $service->publish($section, $review['review_token']));
        self::assertSame(2, $service->setup($section)['published']['version']);
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
    }

    public function testPendingDecisionsBlockAnEmptyPublicationAndAnExplicitEmptyPlanIsRetained(): void
    {
        [$c, $section, $bookings] = $this->school();
        $request = $bookings->request(null, 'Fictional class', [$this->time()], section: $section);
        self::assertSame([$request->id->value], $this->plans($c)->setup($section)['pending_booking_ids']);
        try {
            $this->plans($c)->review($section, 'Fictional approval');
            self::fail('Pending meetings must not silently become an empty publication.');
        } catch (ValidationException $e) {
            self::assertSame('meetings', $e->errors[0]['field']);
        }
        $bookings->cancel($request->id, 0);
        $review = $this->plans($c)->review($section, 'Fictional asynchronous section');
        self::assertSame([], $review['definition']['meetings']);
        $this->plans($c)->publish($section, $review['review_token']);
        self::assertFalse($this->plans($c)->setup($section)['changed']);
        $this->expectException(ValidationException::class);
        $this->plans($c)->review($section, 'Duplicate plan');
    }

    public function testChangedBookingAndRoomLabelsInvalidateTheReviewWithoutAppending(): void
    {
        [$c, $section, $bookings] = $this->school();
        $room = $c->sis->room('SCI.101', 40);
        $booking = $bookings->request($room, 'Fictional class', [$this->time()], section: $section);
        $bookings->approve($booking->id, 0);
        $review = $this->plans($c)->review($section, 'Fictional approval');
        $original = $c->sis->sql->rows('sis_history_events');
        $c->sis->sql->update('sis_rooms', ['name' => 'Updated room'], ['id' => $room->value]);
        try {
            $this->plans($c)->publish($section, $review['review_token']);
            self::fail('A changed location requires a new review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($original, $c->sis->sql->rows('sis_history_events'));
        }
        $review = $this->plans($c)->review($section, 'Fictional revised approval');
        $bookings->cancel($booking->id, 1);
        $this->expectException(ConcurrentModificationException::class);
        $this->plans($c)->publish($section, $review['review_token']);
    }

    public function testAcceptedRetrySurvivesExpiryRetirementAndNewerSelections(): void
    {
        [$c, $section, $bookings] = $this->school();
        $service = $this->plans($c);
        $first = $service->review($section, 'Fictional asynchronous approval');
        $receipt = $service->publish($section, $first['review_token']);
        $booking = $bookings->request(null, 'Fictional online class', [$this->time()], section: $section);
        $bookings->approve($booking->id, 0);
        $second = $service->review($section, 'Fictional meeting added');
        $service->publish($section, $second['review_token']);
        $c->sis->clock->time = $c->sis->clock->time->modify('+1 day');
        $c->sis->sql->update('sis_sections', ['active' => 0], ['id' => $section->value]);
        self::assertSame($receipt, $service->publish($section, $first['review_token']));
        self::assertSame(2, (int) $c->sis->sql->one('sis_section_meeting_plans', ['id' => $section->value])['version']);
    }

    public function testStaleConcurrentReviewAndExpiredUnacceptedReviewAreRejected(): void
    {
        [$c, $section, $bookings] = $this->school();
        $one = $this->plans($c)->review($section, 'First fictional approval');
        $two = $this->plans($c)->review($section, 'Second fictional approval');
        $this->plans($c)->publish($section, $one['review_token']);
        try {
            $this->plans($c)->publish($section, $two['review_token']);
            self::fail('Concurrent reviews must not overwrite each other.');
        } catch (ConcurrentModificationException) {
            self::assertSame(1, (int) $c->sis->sql->one(
                'sis_section_meeting_plans',
                ['id' => $section->value]
            )['version']);
        }
        $b = $bookings->request(null, 'Fictional later meeting', [$this->time()], section: $section);
        $bookings->approve($b->id, 0);
        $r = $this->plans($c)->review($section, 'Fictional new meeting');
        $c->sis->clock->time = $c->sis->clock->time->modify('+21 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $this->plans($c)->publish($section, $r['review_token']);
    }

    public function testBothPermissionsAndTheReviewingActorAreRequired(): void
    {
        [$c, $section] = $this->school();
        $review = $this->plans($c)->review($section, 'Fictional approval');
        $c->sis->access->staff = false;
        $c->sis->access->grants = ['sis:scheduling:manage'];
        try {
            $this->plans($c)->setup($section);
            self::fail('Scheduling alone cannot publish an academic plan.');
        } catch (AccessDeniedException) {
            self::assertSame([], $c->sis->sql->rows('sis_section_meeting_plans'));
        }
        $c->sis->access->grants = ['sis:scheduling:manage', 'sis:catalog:manage'];
        $c->sis->access->actor = EntityId::generate();
        $this->expectException(AccessDeniedException::class);
        $this->plans($c)->publish($section, $review['review_token']);
    }

    public function testAuditFailureRollsBackJournalHeadReceiptAndCurrentReference(): void
    {
        [$c, $section] = $this->school();
        $audit = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new \RuntimeException('Fictional audit failure');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $r = $this->plans($c)->review($section, 'Fictional approval');
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_section_meeting_plans'];
        $before = array_map(fn(string $table): array => $c->sis->sql->rows($table), $tables);
        try {
            $this->plans($c, $audit)->publish($section, $r['review_token']);
            self::fail('Audit failure must abort the decision.');
        } catch (\RuntimeException $e) {
            self::assertSame('Fictional audit failure', $e->getMessage());
        }
        self::assertSame($before, array_map(fn(string $table): array => $c->sis->sql->rows($table), $tables));
        self::assertSame(1, $this->plans($c)->publish($section, $r['review_token'])['last_version']);
    }

    public function testProjectionMismatchAndChangedEncryptedReviewsFailClosed(): void
    {
        [$c, $section] = $this->school();
        $r = $this->plans($c)->review($section, 'Fictional approval');
        try {
            $this->plans($c)->publish($section, $r['review_token'] . 'changed');
            self::fail('An altered review must not be accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $c->sis->sql->rows('sis_section_meeting_plans'));
        }
        $this->plans($c)->publish($section, $r['review_token']);
        $c->sis->sql->update(
            'sis_section_meeting_plans',
            ['definition_hash' => str_repeat('0', 64)],
            ['id' => $section->value]
        );
        $this->expectException(CorruptHistoryException::class);
        $this->plans($c)->setup($section);
    }

    public function testCompleteFiveHundredMeetingPlansFitTheJournalAndOverflowNeverPublishesPartially(): void
    {
        [$c, $section, $bookings] = $this->school();
        $times = [];
        $start = new DateTimeImmutable('2026-08-10T08:00:00-07:00');
        for ($i = 0; $i < 250; $i++) {
            $instant = $start->modify('+' . $i . ' minutes');
            $times[] = new TimeSlot($instant, $instant->modify('+1 minute'));
        }
        for ($i = 0; $i < 2; $i++) {
            $b = $bookings->request(null, 'Fictional online series ' . $i, $times, section: $section);
            $bookings->approve($b->id, 0);
        }
        $review = $this->plans($c)->review($section, 'Fictional large plan approval');
        self::assertCount(500, $review['definition']['meetings']);
        self::assertGreaterThan(65536, strlen(json_encode($review['definition'], JSON_THROW_ON_ERROR)));
        $this->plans($c)->publish($section, $review['review_token']);
        self::assertCount(500, $this->plans($c)->setup($section)['published']['definition']['meetings']);
        $extra = $bookings->request(null, 'Fictional overflow meeting', [$this->time(15)], section: $section);
        $bookings->approve($extra->id, 0);
        $this->expectException(ValidationException::class);
        $this->plans($c)->review($section, 'Must not publish a truncated result');
    }

    public function testFinalPermissionRevocationRollsBackTheEntirePublication(): void
    {
        [$c, $section] = $this->school();
        $audit = new class ($c) implements AuditTrail {
            public function __construct(private CatalogWorkflowFixture $c)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->c->sis->access->staff = false;
                $this->c->sis->access->grants = [];
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $r = $this->plans($c)->review($section, 'Fictional approval');
        $before = $c->sis->sql->rows('sis_history_events');
        try {
            $this->plans($c, $audit)->publish($section, $r['review_token']);
            self::fail('A revoked permission must abort before commit.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $c->sis->sql->rows('sis_history_events'));
            self::assertSame([], $c->sis->sql->rows('sis_section_meeting_plans'));
        }
    }

    public function testPublicationHistoryFreezesItsHeadAndKeepsOlderPlans(): void
    {
        [$c, $section, $bookings] = $this->school();
        $service = $this->plans($c);
        $first = $service->review($section, 'Fictional initial asynchronous plan');
        $service->publish($section, $first['review_token']);
        for ($i = 0; $i < 5; $i++) {
            $b = $bookings->request(null, 'Fictional online meeting', [$this->time()], section: $section);
            $bookings->approve($b->id, 0);
            $r = $service->review($section, 'Fictional meeting added ' . $i);
            $service->publish($section, $r['review_token']);
        }
        $page = $service->versions($section);
        self::assertCount(5, $page['items']);
        self::assertSame(6, $page['through_version']);
        self::assertNotNull($page['next_cursor']);
        $bookings->cancel($b->id, 1);
        $r = $service->review($section, 'Fictional later cancellation');
        $service->publish($section, $r['review_token']);
        $next = $service->versions($section, $page['next_cursor']);
        self::assertSame([6], array_column($next['items'], 'version'));
        self::assertSame(6, $next['through_version']);
        self::assertNull($next['next_cursor']);
        self::assertSame([], $page['items'][0]['definition']['meetings']);
    }

    public function testCategoriesDoNotChangePublishedAcademicMeetings(): void
    {
        [$c, $section, $bookings] = $this->school();
        $b = $bookings->request(null, 'Fictional class', [$this->time()], section: $section);
        $bookings->approve($b->id, 0);
        $r = $this->plans($c)->review($section, 'Fictional approved timetable');
        $this->plans($c)->publish($section, $r['review_token']);
        $category = new \Domain\Scheduling\BookingCategory(
            EntityId::generate(),
            'TEACH',
            'Fictional class label',
            'blue'
        );
        new SqlBookingCategoryRepository($c->sis->sql)->add($category);
        $bookings->categorize($b->id, $category->id, 1);
        self::assertFalse($this->plans($c)->setup($section)['changed']);
    }
}
