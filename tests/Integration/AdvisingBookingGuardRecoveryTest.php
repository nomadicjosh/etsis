<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\BookingPolicySnapshot;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;
use Tests\Support\AdvisingRecoveryServices;
use Tests\Support\WorkflowRecoveryFixture;

/** Owned fictional databases and private bounded processes; no installed records are fixtures. */
final class AdvisingBookingGuardRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $recovery;
    private AdvisingAvailabilityFixture $availability;
    private AdvisingRecoveryServices $commands;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The recovery checks require POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        require_once dirname(__DIR__) . '/Support/AdvisingRecoveryServices.php';
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        $this->recovery = new WorkflowRecoveryFixture();
        $s = $this->recovery->sis;
        $s->clock->time = $s->clock->time->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $this->availability = new AdvisingAvailabilityFixture($s);
        $this->commands = new AdvisingRecoveryServices($s);
    }

    protected function tearDown(): void
    {
        if (isset($this->recovery)) {
            $this->recovery->close();
        }
    }

    private function review(array $changes = []): array
    {
        $p = $this->commands->policies();
        return $p->review(
            array_replace(BookingPolicySnapshot::DEFAULTS, $changes),
            $p->snapshot()->revision,
            'Fictional reviewed booking policy.'
        );
    }

    private function save(array $review): array
    {
        return $this->commands->run('advising_policy_save', $review);
    }

    private function booking(string $window, string $start = '2026-08-11T10:00'): array
    {
        $a = $this->availability->appointments;
        return ['student' => $a->student->value, 'pursuit' => $a->pursuit->value, 'id' => EntityId::generate()->value,
            'window' => $window, 'version' => 0, 'start' => $start];
    }

    private function publication(): array
    {
        $s = $this->recovery->sis;
        $a = $this->availability->appointments;
        $catalog = new \CatalogWorkflowFixture($s);
        $base = $catalog->plan()->sections[0];
        $section = new Section(
            $base->id,
            $base->courseId,
            $base->termId,
            $a->advisor,
            'ADV.RECOVERY.01',
            20,
            false,
            true,
            new Money(0, 'USD'),
            DeliveryMode::Hybrid
        );
        $plan = new OfferingPlan($section->termId, $section);
        $offerings = $catalog->offerings();
        $r = $offerings->review($plan);
        $op = new EntityId($r['operation_id']);
        $offerings->save($op, $plan, $r['revision']);
        $offerings->publish($op, $catalog->publicationReview($offerings, $op)['review_token']);
        $s->service->register($a->student, $section->id, true);
        $bookings = new BookingService(
            new \Infrastructure\Persistence\Repository\SqlBookingRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            new \Infrastructure\Persistence\Repository\SqlRoomRepository($s->sql),
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlBookingCategoryRepository($s->sql),
            $s->bookingSections()
        );
        $from = new \DateTimeImmutable('2026-08-11T10:00:00-07:00');
        $b = $bookings->request(
            null,
            'Fictional class purpose.',
            [new TimeSlot($from, $from->modify('+1 hour'))],
            section: $section->id
        );
        $bookings->approve($b->id, 0);
        $review = $this->commands->meetingPlans()->review($section->id, 'Fictional confirmed class schedule.');
        return ['section' => $section->id->value, 'token' => $review['review_token']];
    }

    private function waiting(int $worker): void
    {
        $this->recovery->send($worker, 'go');
        $this->recovery->await($worker, 'running');
        $this->recovery->awaitLock($worker);
    }

    private function release(int $worker, bool $commit): void
    {
        if ($commit) {
            $this->recovery->send($worker, 'continue');
            self::assertSame('accepted', $this->recovery->result($worker)['state']);
        } else {
            $this->recovery->kill($worker);
        }
    }

    public function testPolicyProcessLossAndOriginalReceiptAfterLaterPolicyAndExpiry(): void
    {
        $r = $this->recovery; $review = $this->review(['student_class_mode' => 'block',
            'advisor_teaching_mode' => 'block']);
        $before = $r->facts();
        $first = $r->start('advising_policy_save', $review, 'advising.booking_policy_saved');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $r->kill($first);
        self::assertSame($before, $r->facts());
        $lost = $r->start('advising_policy_save', $review, loseReply: true);
        $r->send($lost, 'go');
        $r->await($lost, 'committed');
        $r->kill($lost);
        $receipt = $this->save($review);
        $this->save($this->review(['maximum_active' => 1]));
        $r->sis->clock->time = $r->sis->clock->time->modify('+1 day');
        $after = $r->facts();
        self::assertSame($receipt, $this->save($review));
        self::assertSame($after, $r->facts());
    }

    #[DataProvider('outcomes')]
    public function testCompetingReviewedPolicyConfirmationsRespectCommitAndProcessLoss(bool $commit): void
    {
        $r = $this->recovery;
        $one = $this->review(['maximum_active' => 1]);
        $two = $this->review(['maximum_active' => 2]);
        $first = $r->start('advising_policy_save', $one, 'advising.booking_policy_saved');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_policy_save', $two);
        $this->waiting($second);
        $this->release($first, $commit);
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        self::assertSame($commit ? 1 : 2, $this->commands->policies()->snapshot()->rules['maximum_active']);
        self::assertCount(1, $r->sis->sql->rows('sis_advising_booking_policies'));
    }

    #[DataProvider('outcomes')]
    public function testClassPublicationSerializesBeforeBookingWithoutPartialChecks(bool $commit): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $publish = $this->publication();
        $this->save($this->review(['student_class_mode' => 'block', 'advisor_teaching_mode' => 'block']));
        $window = $this->availability->offer()['id'];
        $first = $r->start('advising_class_publish', $publish, 'section.meeting_plan_published');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $r->sis->access->actor = $a->owner;
        $second = $r->start('advising_book', $this->booking($window));
        $this->waiting($second);
        $this->release($first, $commit);
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        self::assertCount($commit ? 0 : 1, $r->sis->sql->rows('sis_advising_appointments'));
        self::assertCount($commit ? 1 : 0, $r->sis->sql->rows('sis_section_meeting_plans'));
    }

    public function testBookingCommitsBeforeLaterClassPublicationAndOriginalReceiptRemainsExact(): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $publish = $this->publication();
        $this->save($this->review(['student_class_mode' => 'block', 'advisor_teaching_mode' => 'block']));
        $window = $this->availability->offer()['id'];
        $r->sis->access->actor = $a->owner;
        $command = $this->booking($window);
        $first = $r->start('advising_book', $command, 'advising.appointment_self_booked');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $r->sis->access->actor = $a->staff;
        $second = $r->start('advising_class_publish', $publish);
        $this->waiting($second);
        $r->send($first, 'continue');
        $result = $r->result($first);
        self::assertSame('accepted', $result['state']);
        self::assertSame('accepted', $r->result($second)['state']);
        $r->sis->access->actor = $a->owner;
        $before = $r->facts();
        self::assertSame($result['result'], $this->commands->run('advising_book', $command));
        self::assertSame($before, $r->facts());
    }

    #[DataProvider('outcomes')]
    public function testActiveLimitSerializesCompetingDifferentSlotsAndReleasesAfterProcessLoss(bool $commit): void
    {
        $r = $this->recovery;
        $a = $this->availability->appointments;
        $this->save($this->review(['maximum_active' => 1]));
        $window = $this->availability->offer()['id'];
        $r->sis->access->actor = $a->owner;
        $one = $this->booking($window);
        $two = $this->booking($window, '2026-08-11T10:30');
        $first = $r->start('advising_book', $one, 'advising.appointment_self_booked');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('advising_book', $two);
        $this->waiting($second);
        $this->release($first, $commit);
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        self::assertCount(1, $r->sis->sql->rows('sis_advising_appointments'));
    }

    public static function outcomes(): array
    {
        return [[true], [false]];
    }
}
