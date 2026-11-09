<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/HiatusFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\Student;
use Domain\Student\StudentActivity;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusImpact;
use Domain\Student\ValueObject\HiatusReturnPlan;
use Domain\Student\ValueObject\StudentActivityId;
use PHPUnit\Framework\TestCase;

final class StudentActivityHistoryTest extends TestCase
{
    private function revision(SisFixture $f, EntityId $id): string
    {
        $s = $f->admissions->student($id);
        return hash(
            "sha256",
            json_encode(
                [$id->value, $s->personId->value, $s->programId->value, $s->entryTermId->value, $s->active],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function review(SisFixture $f, EntityId $id, bool $active, string $on = "2026-08-10"): array
    {
        return $f
            ->activityService()
            ->review($id, $active, $on, "Fictional protected activity reason.", $this->revision($f, $id));
    }
    private function decide(SisFixture $f, EntityId $id, bool $active, string $on): array
    {
        return $f->activityService()->commit($id, $this->review($f, $id, $active, $on)["review_token"]);
    }
    private function replay(SisFixture $f, EntityId $id, ?string $on = null): StudentActivity
    {
        $root = StudentActivity::root(new StudentActivityId($id->value));
        if ($on !== null) {
            $root->effectiveThrough($on);
        }
        $activityCases1 = EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor(new StudentActivityId($id->value));
        foreach ($activityCases1 as $e) {
            $root->acceptHistorical($e);
        }
        return $root;
    }
    public function testReadsAndReviewsDoNotInventEventsAndTheFirstAcceptedDecisionIsExplicitlyObserved(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        self::assertFalse($f->activityService()->history($id)["history_recorded"]);
        $preview = $this->review($f, $id, false);
        self::assertTrue($preview["current_active"]);
        self::assertFalse($preview["resulting_active"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_student_activity"));
        $receipt = $f->activityService()->commit($id, $preview["review_token"]);
        self::assertSame(2, $receipt["version"]);
        self::assertFalse($f->admissions->student($id)->active);
        $page = $f->activityService()->history($id);
        self::assertSame(["observation", "decision"], array_column($page["items"], "kind"));
        self::assertTrue($page["earlier_history_unknown"]);
        self::assertSame("observed_existing", $page["items"][0]["source"]);
        self::assertSame($f->admissions->student($id)->active, $this->replay($f, $id)->active());
        self::assertStringNotContainsString(
            "Fictional protected activity reason.",
            json_encode($f->sql->rows("sis_history_events")),
        );
        self::assertStringNotContainsString(
            "Fictional protected activity reason.",
            json_encode($f->sql->rows("sis_audit")),
        );
        self::assertStringNotContainsString("reason", json_encode($page));
        self::assertStringNotContainsString("actor_id", json_encode($page));
    }
    public function testBackdatedDecisionsChangeTheEarlierPeriodWithoutUndoingLaterActivation(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $this->decide($f, $id, false, "2026-07-01");
        $this->decide($f, $id, true, "2026-08-05");
        $before = $f->sql->rows("sis_history_events");
        $preview = $this->review($f, $id, true, "2026-07-15");
        self::assertFalse($preview["active_on_date"]);
        self::assertTrue($preview["resulting_active"]);
        $f->activityService()->commit($id, $preview["review_token"]);
        $retro = $this->review($f, $id, false, "2026-07-20");
        self::assertTrue($retro["active_on_date"]);
        self::assertTrue($retro["resulting_active"]);
        $f->activityService()->commit($id, $retro["review_token"]);
        self::assertTrue($f->admissions->student($id)->active);
        self::assertNull($this->replay($f, $id, "2026-06-30")->active());
        self::assertFalse($this->replay($f, $id, "2026-07-10")->active());
        self::assertTrue($this->replay($f, $id, "2026-07-16")->active());
        self::assertFalse($this->replay($f, $id, "2026-07-21")->active());
        self::assertTrue($this->replay($f, $id, "2026-08-05")->active());
        self::assertSame($before, array_slice($f->sql->rows("sis_history_events"), 0, count($before)));
    }
    public function testSameDateTieUsesTheLaterRecordedDecisionAndKeepsBothFacts(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $this->decide($f, $id, false, "2026-08-10");
        $this->decide($f, $id, true, "2026-08-10");
        self::assertTrue($this->replay($f, $id, "2026-08-10")->active());
        self::assertCount(3, $f->activityService()->history($id)["items"]);
    }
    public function testExactRetryReturnsTheOriginalOutcomeWithoutResettingALaterStatus(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $review = $this->review($f, $id, false);
        $receipt = $f->activityService()->commit($id, $review["review_token"]);
        $this->decide($f, $id, true, "2026-08-10");
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($receipt, $f->activityService()->commit($id, $review["review_token"]));
        self::assertTrue($f->admissions->student($id)->active);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
    }
    public function testAnUnchangedCurrentFlagDoesNotAllowAStaleTimelineReview(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $this->decide($f, $id, false, "2026-07-01");
        $this->decide($f, $id, true, "2026-08-05");
        $stale = $this->review($f, $id, false);
        $this->decide($f, $id, true, "2026-07-15");
        $this->expectException(ConcurrentModificationException::class);
        $f->activityService()->commit($id, $stale["review_token"]);
    }
    public function testAuditFailureRollsBackTheObservationDecisionReceiptAndProjection(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $review = $this->review($f, $id, false);
        $before = $f->sql->rows("sis_history_events");
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional failure"));
        try {
            $f->activityService($audit)->commit($id, $review["review_token"]);
            self::fail("An unaudited activity decision committed.");
        } catch (RuntimeException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_history_operations"));
            self::assertSame([], $f->sql->rows("sis_student_activity"));
            self::assertTrue($f->admissions->student($id)->active);
        }
    }
    public function testOwnersAndReadOnlyStaffCannotReadActivityHistoryOrWriteDecisions(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $review = $this->review($f, $id, false);
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $activityCases2 = [
            fn() => $f->activityService()->history($id),
            fn() => $f->activityService()->commit($id, $review["review_token"]),
            fn() => $this->review($f, $id, false),
        ];
        foreach ($activityCases2 as $action) {
            try {
                $action();
                self::fail("Read/owner access granted activity management.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testReviewTokensAreBoundToActorStudentAndPurposeAndRejectTampering(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $other = $f->student()->id;
        $review = $this->review($f, $id, false);
        $original = $f->access->actor;
        $otherActor = $f->user();
        $activityCases3 = [
            [$other, $original, $review["review_token"]],
            [$id, $otherActor, $review["review_token"]],
            [$id, $original, $review["review_token"] . "x"],
            [$id, $original, $f->dropCipher->seal('{"purpose":"another-workflow"}')],
        ];
        foreach ($activityCases3 as [$target, $actor, $token]) {
            $f->access->actor = $actor;
            try {
                $f->activityService()->commit($target, $token);
                self::fail("An invalid review token was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testExpiredNewReviewIsRejectedButNoEventsAreWritten(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $review = $this->review($f, $id, false);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->activityService()->commit($id, $review["review_token"]);
    }
    public function testInvalidDatesMissingReasonsAndKnownNoOpsAreRejected(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $activityCases4 = [
            ["2026-02-30", "Reason", false],
            ["2026-08-11", "Reason", false],
            ["2026-08-10", "", false],
            ["2026-08-10", "Reason\0", false],
            ["2026-08-10", "Reason", true],
            ["0000-01-01", "Reason", false],
        ];
        foreach ($activityCases4 as [$on, $reason, $active]) {
            try {
                $f->activityService()->review($id, $active, $on, $reason, $this->revision($f, $id));
                self::fail("An invalid activity decision was reviewed.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testProjectionTamperingBlocksRegistrationAndFurtherDecisions(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        $this->decide($f, $id, false, "2026-08-10");
        $f->sql->update("sis_students", ["active" => 1], ["id" => $id->value]);
        $section = $f->section();
        $events = count($f->sql->rows("sis_history_events"));
        $activityCases5 = [
            fn() => $f->service->register($id, $section->id, true),
            fn() => $f->activityService()->history($id),
            fn() => $this->review($f, $id, false),
        ];
        foreach ($activityCases5 as $action) {
            try {
                $action();
                self::fail("A drifted projection was used.");
            } catch (CorruptHistoryException) {
                self::assertSame($events, count($f->sql->rows("sis_history_events")));
                self::assertSame([], $f->sql->rows("sis_enrollments"));
            }
        }
    }
    public function testMissingOrChangedCheckpointsFailClosedWithoutRepairingHistory(): void
    {
        foreach (["missing", "version", "selected"] as $case) {
            $f = new SisFixture();
            $id = $f->student()->id;
            $this->decide($f, $id, false, "2026-08-10");
            if ($case === "missing") {
                $f->sql->execute("DELETE FROM sis_student_activity WHERE id = ?", [$id->value]);
            } else {
                $f->sql->update(
                    "sis_student_activity",
                    $case === "version"
                        ? ["stream_version" => 99]
                        : ["current_decision_id" => EntityId::generate()->value],
                    ["id" => $id->value],
                );
            }
            try {
                $f->activityService()->history($id);
                self::fail("A damaged checkpoint was accepted.");
            } catch (CorruptHistoryException) {
                self::assertCount(2, $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testHistoryUsesBoundedFrozenPagesAndDoesNotExposeCaseReasons(): void
    {
        $f = new SisFixture();
        $id = $f->student()->id;
        for ($i = 0; $i < 12; ++$i) {
            $this->decide($f, $id, $i % 2 === 1, "2026-08-10");
        }
        $first = $f->activityService()->history($id);
        self::assertCount(10, $first["items"]);
        $this->decide($f, $id, false, "2026-08-10");
        $next = $f->activityService()->history($id, $first["next_cursor"]);
        self::assertCount(3, $next["items"]);
        self::assertNull($next["next_cursor"]);
        self::assertFalse($next["active"]);
        self::assertStringNotContainsString("reason", json_encode($next));
    }
    public function testHiatusAndReturnUseTheSameActivityStreamAndRetainOriginalCaseReceipts(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $id = $f->student()->id;
        $dates = new DateRange(new DateTimeImmutable("2026-08-01"));
        $review = $h->workflow()->review($id, HiatusCode::Withdrawal, $dates);
        $case = new StudentHiatus(new EntityId($review["operation_id"]), $id, HiatusCode::Withdrawal, $dates);
        $impact = new HiatusImpact(true, $review["revision"], []);
        $receipt = $h->workflow()->commit($case, "Fictional case reason", $impact);
        $ret = $h->returns()->review($id, $case->id, new DateTimeImmutable("2026-08-10"));
        $plan = new HiatusReturnPlan(
            new EntityId($ret["operation_id"]),
            new DateTimeImmutable("2026-08-10"),
            $ret["revision"],
            true,
        );
        $h->returns()->commit($id, $case->id, $plan, "Fictional return reason");
        $events = $f->sql->rows("sis_history_events");
        self::assertSame($receipt, $h->workflow()->commit($case, "Fictional case reason", $impact));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertTrue($f->admissions->student($id)->active);
        $items = $f->activityService()->history($id)["items"];
        self::assertSame(["observed_existing", "hiatus", "hiatus_return"], array_column($items, "source"));
        self::assertFalse($this->replay($f, $id, "2026-08-05")->active());
        self::assertTrue($this->replay($f, $id, "2026-08-10")->active());
    }
    public function testConflictingLaterActivityRollsBackTheEntireHiatusDropPlan(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $id = $f->student()->id;
        $this->decide($f, $id, false, "2026-07-01");
        $this->decide($f, $id, true, "2026-08-10");
        $section = $f->section(10, 1250);
        $f->service->register($id, $section->id);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $dates = new DateRange(new DateTimeImmutable("2026-08-10"));
        $review = $h->workflow()->review($id, HiatusCode::Withdrawal, $dates);
        // Same effective day would supersede the activation, so use an earlier reviewed day.
        $f->clock->time = new DateTimeImmutable("2026-08-12T10:00:00-07:00");
        $this->decide($f, $id, false, "2026-08-11");
        $this->decide($f, $id, true, "2026-08-12");
        $review = $h->workflow()->review($id, HiatusCode::Withdrawal, $dates);
        $case = new StudentHiatus(new EntityId($review["operation_id"]), $id, HiatusCode::Withdrawal, $dates);
        $before = $f->sql->rows("sis_history_events");
        $enrollments = $f->sql->rows("sis_enrollments");
        $credits = $f->sql->rows("sis_academic_credits");
        try {
            $h->workflow()->commit(
                $case,
                "Fictional conflicting hiatus",
                new HiatusImpact(true, $review["revision"], [], $section->id),
            );
            self::fail("A contradictory current-status receipt was committed.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame($enrollments, $f->sql->rows("sis_enrollments"));
            self::assertSame($credits, $f->sql->rows("sis_academic_credits"));
            self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
            self::assertTrue($f->admissions->student($id)->active);
        }
    }
}
