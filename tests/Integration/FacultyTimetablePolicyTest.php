<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\FacultyScheduleRepository;
use Domain\Registration\Service\FacultyScheduleService;
use Domain\Registration\Service\PublishedSectionMeetingPlanReader;
use Domain\Registration\Service\SectionMeetingPlanService;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlFacultyScheduleRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AcademicTimetableFixture;
use Tests\Support\RegistrationScheduleFixture;

final class FacultyTimetablePolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/AcademicTimetableFixture.php";
        require_once dirname(__DIR__) . "/Support/RegistrationScheduleFixture.php";
    }

    private function meeting(
        AcademicTimetableFixture $t,
        EntityId $section,
        int $hour = 10,
    ): \Domain\Scheduling\Booking {
        $start = new DateTimeImmutable(sprintf("2026-08-10T%02d:00:00-07:00", $hour));
        $b = $t->bookings->request(
            null,
            "Private fictional purpose",
            [new TimeSlot($start, $start->modify("+1 hour"))],
            section: $section,
        );
        $t->bookings->approve($b->id, 0);
        return $b;
    }

    private function policy(AcademicTimetableFixture $t, string $mode): void
    {
        $p = new RegistrationScheduleFixture($t->sis)->policy();
        $current = $p->current();
        $p->save($current["mode"], $current["revision"], facultyMode: $mode);
    }

    private function digest(AcademicTimetableFixture $t): array
    {
        return array_map($t->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_section_meeting_plans",
            "sis_audit",
        ]);
    }

    public function testDefaultBlocksOverlappingPublishedFacultyClassesWithoutChangingHistory(): void
    {
        $t = new AcademicTimetableFixture();
        $other = $t->addSection();
        $this->meeting($t, $t->section->id);
        $this->meeting($t, $other->id);
        $t->publish();
        $summary = $t->publications->setup($other->id)["faculty_assessment"];
        self::assertTrue($summary["blocked"]);
        self::assertSame(1, $summary["pairs"]);
        self::assertSame($t->section->code, $summary["conflicts"][0]["section_code"]);
        $wire = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($t->sis->access->actor->value, $wire);
        self::assertStringNotContainsString("occurrence_id", $wire);
        self::assertStringNotContainsString("Private fictional", $wire);
        $before = $this->digest($t);
        try {
            $t->publications->review($other->id, "Fictional blocked review");
            self::fail("An overlap needs resolution under blocking policy.");
        } catch (ValidationException) {
            self::assertSame($before, $this->digest($t));
        }
    }

    public function testWarningPublicationRetainsExactEvidenceAndRetrySurvivesLaterPolicyAndBookingChanges(): void
    {
        $t = new AcademicTimetableFixture();
        $this->policy($t, "warn");
        $other = $t->addSection();
        $this->meeting($t, $t->section->id);
        $b = $this->meeting($t, $other->id);
        $t->publish();
        $review = $t->publications->review($other->id, "Fictional considered warning");
        self::assertSame("warn", $review["faculty_assessment"]["mode"]);
        self::assertSame(1, $review["faculty_assessment"]["pairs"]);
        $receipt = $t->publications->publish($other->id, $review["review_token"]);
        $published = $t->publications->setup($other->id)["published"];
        self::assertSame($review["faculty_assessment"], $published["faculty_assessment"]);
        $head = new PublishedSectionMeetingPlanReader($t->plans, $t->history)->read($other->id);
        $facts = $head->event->metadata()["faculty_assessment"];
        self::assertSame([$t->sis->access->actor->value], $facts["faculty_ids"]);
        self::assertSame($t->section->id->value, $facts["sources"][0]["section_id"]);
        self::assertSame(1, $facts["sources"][0]["version"]);
        self::assertCount(1, $facts["conflicts"]);
        $this->policy($t, "block");
        $t->bookings->cancel($b->id, 1);
        $t->publish($other->id);
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        $before = $this->digest($t);
        self::assertSame($receipt, $t->publications->publish($other->id, $review["review_token"]));
        self::assertSame($before, $this->digest($t));
    }

    public function testTwoInitiallyClearReviewsCannotBothPublishConflictingSchedules(): void
    {
        $t = new AcademicTimetableFixture();
        $other = $t->addSection();
        $this->meeting($t, $t->section->id);
        $this->meeting($t, $other->id);
        $a = $t->publications->review($t->section->id, "Fictional first review");
        $b = $t->publications->review($other->id, "Fictional second review");
        self::assertSame("clear", $a["faculty_assessment"]["status"]);
        self::assertSame("clear", $b["faculty_assessment"]["status"]);
        $t->publications->publish($t->section->id, $a["review_token"]);
        $before = $this->digest($t);
        try {
            $t->publications->publish($other->id, $b["review_token"]);
            self::fail("Another publication invalidates this reviewed comparison.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($t));
        }
    }

    public function testBackToBackClassesAreAllowedAndWorkingCancellationCannotErasePublishedConflict(): void
    {
        $t = new AcademicTimetableFixture();
        $other = $t->addSection();
        $b = $this->meeting($t, $t->section->id);
        $this->meeting($t, $other->id);
        $t->publish();
        $t->bookings->cancel($b->id, 1);
        self::assertTrue($t->publications->setup($other->id)["faculty_assessment"]["blocked"]);
        $t->publish();
        self::assertFalse($t->publications->setup($other->id)["faculty_assessment"]["blocked"]);
        $this->meeting($t, $t->section->id, 11);
        $t->publish();
        $t->publish($other->id);
        self::assertSame(0, $t->publications->setup($other->id)["published"]["faculty_assessment"]["pairs"]);
    }

    public function testCurrentFacultyReassignmentCannotRewriteTheFacultyOfRetainedMeetings(): void
    {
        $t = new AcademicTimetableFixture();
        $other = $t->addSection();
        $this->meeting($t, $t->section->id);
        $this->meeting($t, $other->id);
        $t->publish();
        $t->sis->sql->update(
            "sis_sections",
            ["faculty_user_id" => $t->sis->user()->value],
            ["id" => $t->section->id->value],
        );
        self::assertTrue($t->publications->setup($other->id)["faculty_assessment"]["blocked"]);
    }

    public function testPolicyChangeInvalidatesAnUnacceptedReview(): void
    {
        $t = new AcademicTimetableFixture();
        $this->meeting($t, $t->section->id);
        $review = $t->publications->review($t->section->id, "Fictional approved review");
        $this->policy($t, "warn");
        $before = $this->digest($t);
        try {
            $t->publications->publish($t->section->id, $review["review_token"]);
            self::fail("A changed policy needs review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($t));
        }
    }

    public function testPostAuditPolicyChangeRollsBackTheWholePublication(): void
    {
        $t = new AcademicTimetableFixture();
        $this->meeting($t, $t->section->id);
        $r = $t->publications->review($t->section->id, "Fictional approved review");
        $audit = new class ($t) implements AuditTrail {
            public function __construct(private AcademicTimetableFixture $t)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $p = new SqlRegistrationScheduleRepository($this->t->sis->sql);
                $current = $p->policy();
                $p->savePolicy($current["mode"], $current["revision"], "warn");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $reader = new PublishedSectionMeetingPlanReader($t->plans, $t->history);
        $service = new SectionMeetingPlanService(
            $t->plans,
            $t->history,
            $t->sis->access,
            $t->sis->transactions,
            $audit,
            $t->catalog->fixture->cipher,
            $t->sis->clock,
            $reader,
            new FacultyScheduleService(
                new SqlFacultyScheduleRepository($t->sis->sql),
                new SqlRegistrationScheduleRepository($t->sis->sql),
                $t->history,
                $reader,
            ),
        );
        $before = $this->digest($t);
        try {
            $service->publish($t->section->id, $r["review_token"]);
            self::fail("Post-audit changes cannot be committed with stale evidence.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($t));
            self::assertSame(
                "block",
                new SqlRegistrationScheduleRepository($t->sis->sql)->facultyPolicy()["mode"],
            );
        }
    }

    public function testCandidateLimitRejectsPartialConflictAssessments(): void
    {
        $t = new AcademicTimetableFixture();
        $this->meeting($t, $t->section->id);
        $records = new class implements FacultyScheduleRepository {
            public function candidates(
                array $faculty,
                string $firstDay,
                string $lastDay,
                EntityId $excluding,
            ): array {
                return array_map(static fn(): string => EntityId::generate()->value, range(1, 51));
            }
        };
        $service = new FacultyScheduleService(
            $records,
            new SqlRegistrationScheduleRepository($t->sis->sql),
            $t->history,
            new PublishedSectionMeetingPlanReader($t->plans, $t->history),
        );
        $definition = new SectionMeetingPlanDefinition($t->publications->setup($t->section->id)["working"]);
        $this->expectException(ValidationException::class);
        $service->assess($definition);
    }
}
