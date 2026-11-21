<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\HumanResources\ValueObject\TimeApprovalRules;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class TimeApprovalPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student();
        $service = $s->timesheetService();
        $employee = $service->addEmployee($student->personId, "Fictional instructor");
        return [$s, $service, $employee];
    }
    private function publish(SisFixture $s, array $changes = []): array
    {
        $policy = $s->timeApprovalPolicies();
        $current = $policy->view();
        $rules = array_replace(
            $current["rules"],
            [
                "daily_minutes" => 120,
                "weekly_minutes" => 180,
                "overlaps" => true,
                "mode" => "exception_required",
            ],
            $changes,
        );
        $r = $policy->review(
            $rules,
            $current["version"],
            "Fictional institutional review thresholds.",
        );
        return $policy->publish(
            new EntityId($r["publication_id"]),
            $r["rules"],
            $r["version"],
            $r["reason"],
            $r["review_token"],
        );
    }
    private function submit(
        object $service,
        EntityId $employee,
        string $from = "2026-08-10T09:00:00+00:00",
        string $to = "2026-08-10T11:00:00+00:00",
    ): object {
        return $service->submit(
            $employee,
            new TimeSlot(new DateTimeImmutable($from), new DateTimeImmutable($to)),
        );
    }
    public function testDefaultPolicyAndOrdinaryDecisionKeepElapsedTimeAndContactUnitsSeparate(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $policy = $s->timeApprovalPolicies()->view();
        self::assertSame(0, $policy["version"]);
        self::assertNull($policy["rules"]["daily_minutes"]);
        self::assertSame(50, $policy["rules"]["contact_unit_minutes"]);
        $entry = $this->submit($service, $employee);
        $s->access->actor = $s->user();
        $service->decide($entry->id, true, 0);
        $detail = $service->entryDetails($entry->id);
        self::assertSame(7200, $detail["duration_seconds"]);
        self::assertTrue($detail["decision_recorded"]);
        self::assertSame([], $detail["time_review"]["flags"]);
        $before = $s->sql->rows("sis_history_events");
        $service->decide($entry->id, true, 0);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }
    public function testDailyWeeklyAndOverlapFlagsRequireIndependentDutyAndReason(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $this->publish($s);
        $this->submit($service, $employee);
        $entry = $this->submit(
            $service,
            $employee,
            "2026-08-10T12:00:00+00:00",
            "2026-08-10T14:00:00+00:00",
        );
        // Earlier working/imported overlap is observed, never invented as a past approval.
        $s->sql->update(
            "sis_timesheets",
            ["starts_at" => "2026-08-10 10:00:00", "ends_at" => "2026-08-10 12:00:00"],
            ["id" => $entry->id->value],
        );
        $s->access->actor = $s->user();
        $detail = $service->entryDetails($entry->id);
        self::assertEqualsCanonicalizing(
            ["daily_limit", "weekly_limit", "overlap"],
            $detail["time_review"]["flags"],
        );
        self::assertTrue($detail["time_review"]["requires_exception"]);
        $s->access->staff = false;
        $s->access->grants = ["sis:hr:manage"];
        try {
            $service->decide(
                $entry->id,
                true,
                0,
                $detail["time_review"]["revision"],
                "Fictional approved exception.",
            );
            self::fail("Missing independent duty accepted.");
        } catch (AccessDeniedException) {
            self::assertSame("pending", $service->get($entry->id)->status);
        }
        $s->access->grants[] = "sis:hr:time-exception";
        try {
            $service->decide($entry->id, true, 0, $detail["time_review"]["revision"]);
            self::fail("Missing reason accepted.");
        } catch (ValidationException) {
            self::assertSame("pending", $service->get($entry->id)->status);
        }
        $service->decide(
            $entry->id,
            true,
            0,
            $detail["time_review"]["revision"],
            "Fictional approved exception.",
        );
        self::assertSame("approved", $service->get($entry->id)->status);
        self::assertSame(
            "Fictional approved exception.",
            $service->entryDetails($entry->id)["exception_reason"],
        );
    }
    public function testChangedNeighborAndPolicyRejectStaleApprovalAndRetainedEvidenceSurvivesLaterChanges(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $this->publish($s, ["mode" => "advisory"]);
        $entry = $this->submit($service, $employee);
        $r = $service->entryDetails($entry->id)["time_review"];
        $this->submit(
            $service,
            $employee,
            "2026-08-10T12:00:00+00:00",
            "2026-08-10T13:00:00+00:00",
        );
        $s->access->actor = $s->user();
        try {
            $service->decide($entry->id, true, 0, $r["revision"]);
            self::fail("Changed neighbor ignored.");
        } catch (ConcurrentModificationException) {
            self::assertSame("pending", $service->get($entry->id)->status);
        }
        $r = $service->entryDetails($entry->id)["time_review"];
        $service->decide($entry->id, true, 0, $r["revision"]);
        $saved = $service->entryDetails($entry->id)["time_review"];
        $this->publish($s, ["daily_minutes" => 300, "contact_unit_minutes" => 60]);
        self::assertSame($saved, $service->entryDetails($entry->id)["time_review"]);
        self::assertSame(1, $saved["policy"]["version"]);
        $service->decide($entry->id, true, 0, $r["revision"]);
        self::assertSame($saved, $service->entryDetails($entry->id)["time_review"]);
    }
    public function testRejectedIntervalsAreExcludedAndAdjacentIntervalsDoNotOverlap(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $this->publish($s);
        $a = $this->submit($service, $employee);
        $b = $this->submit(
            $service,
            $employee,
            "2026-08-10T11:00:00+00:00",
            "2026-08-10T12:00:00+00:00",
        );
        $s->access->actor = $s->user();
        $service->decide($a->id, false, 0);
        $detail = $service->entryDetails($b->id);
        self::assertSame([], $detail["time_review"]["flags"]);
        self::assertSame(3600, $detail["time_review"]["days"][0]["seconds"]);
        self::assertSame(0, $detail["time_review"]["overlap_count"]);
    }
    public function testPolicyPublisherNeedsBothDutiesAndStaleOrExpiredReviewsDoNotAppend(): void
    {
        [$s] = $this->fixture();
        $current = $s->timeApprovalPolicies()->view();
        $review = $s
            ->timeApprovalPolicies()
            ->review($current["rules"], 0, "Fictional policy review.");
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $s->timeApprovalPolicies()->publish(
                new EntityId($review["publication_id"]),
                $review["rules"],
                0,
                $review["reason"],
                $review["review_token"],
            );
            self::fail("Expired policy accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(0, $s->timeApprovalPolicies()->view()["version"]);
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:hr:manage"];
        $this->expectException(AccessDeniedException::class);
        $s->timeApprovalPolicies()->view();
    }
    public function testAuditTimePolicyChangeRollsBackDecisionAndNestedPublication(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $this->publish($s, ["mode" => "advisory"]);
        $entry = $this->submit($service, $employee);
        $s->access->actor = $s->user();
        $review = $service->entryDetails($entry->id)["time_review"];
        $tables = ["sis_timesheets", "sis_history_events", "sis_audit", "sis_time_policy_state"];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method("record")->willReturnCallback(function () use ($s): void {
            $this->publish($s, ["daily_minutes" => 30]);
        });
        try {
            $s->timesheetService($audit)->decide($entry->id, true, 0, $review["revision"]);
            self::fail("A changed policy was accepted after the required audit.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }
    public function testCurrentCounterDisagreementFailsClosedWithoutCreatingAnObservedPolicy(): void
    {
        [$s] = $this->fixture();
        $before = $s->sql->rows("sis_history_events");
        $s->sql->update(
            "sis_time_policy_state",
            ["version" => 1],
            ["id" => \Domain\HumanResources\TimeApprovalPolicy::ID],
        );
        try {
            $s->timeApprovalPolicies()->view();
            self::fail("A source disagreement was hidden by an older snapshot.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }
    public function testDaylightSavingUsesElapsedSecondsAndClipsBothWeeks(): void
    {
        [$s, $service, $employee] = $this->fixture();
        $this->publish($s, ["daily_minutes" => 90, "weekly_minutes" => null, "timezone" => "America/Los_Angeles"]);
        $entry = $this->submit($service, $employee, "2026-03-08T01:30:00-08:00", "2026-03-08T03:30:00-07:00");
        $review = $service->entryDetails($entry->id)["time_review"];
        self::assertSame(3600, $review["days"][0]["seconds"]);
        self::assertSame([], $review["flags"]);
        $other = $this->submit($service, $employee, "2026-03-08T23:30:00-07:00", "2026-03-09T00:30:00-07:00");
        $review = $service->entryDetails($other->id)["time_review"];
        self::assertCount(2, $review["weeks"]);
        self::assertSame(1800, $review["weeks"][1]["seconds"]);
    }
}
