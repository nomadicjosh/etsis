<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\EnrollmentHistory;
use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use Tests\Support\RegistrationScheduleFixture;

final class RegistrationScheduleTest extends TestCase
{
    private function fixture(): RegistrationScheduleFixture
    {
        require_once dirname(__DIR__) .
        "/Support/RegistrationScheduleFixture.php";
        return new RegistrationScheduleFixture();
    }
    private function pair(RegistrationScheduleFixture $t): array
    {
        $f = $t->sis;
        $student = $f->student();
        $a = $f->section();
        $b = $f->section();
        $t->publish($a, [["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"]]);
        $t->publish($b, [["2026-09-01T16:30:00Z", "2026-09-01T17:30:00Z"]]);
        $f->service->register($student->id, $a->id, true);
        return [$student, $a, $b];
    }
    public function testDefaultPolicyBlocksConfirmedOnlineOrRoomFreeOverlapsWithoutAcademicOrFinancialWrites(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $review = $f->service->reviewSchedule($student->id, $b->id);
        self::assertSame("conflict", $review["status"]);
        self::assertTrue($review["blocked"]);
        self::assertSame($a->code, $review["conflicts"][0]["section_code"]);
        self::assertSame(
            "2026-09-01T16:30:00Z",
            $review["conflicts"][0]["starts_at"],
        );
        $before = $t->digest();
        foreach ([true, false] as $staff) {
            try {
                $f->service->register($student->id, $b->id, $staff);
                self::fail("An overlapping registration was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $t->digest());
            }
        }
    }
    public function testWarningPolicyRetainsItsExactPlanReferencesAndOriginalRetryAfterLaterChanges(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $policy = $t->policy()->save("warn", 0);
        self::assertSame(1, $policy["revision"]);
        $enrollment = $f->service->register($student->id, $b->id, true);
        $history = EventStoreFixture::history($f->connection->queryBuilder());
        $stream = new StreamReference(
            new EnrollmentHistoryId($enrollment->id->value),
            "enrollment",
        );
        $event = $history->latest($stream)->event;
        $facts = $event->metaParam("schedule_evidence");
        self::assertSame(["mode" => "warn", "revision" => 1], $facts["policy"]);
        self::assertCount(2, $facts["plans"]);
        self::assertCount(1, $facts["conflicts"]);
        $root = EnrollmentHistory::root(
            new EnrollmentHistoryId($enrollment->id->value),
        );
        $root->acceptHistorical($event);
        self::assertSame(1, $root->scheduleSummary()["conflict_count"]);
        self::assertFalse($root->scheduleSummary()["blocked"]);
        $t->policy()->save("block", 1);
        $t->publish($b, []);
        $before = $t->digest();
        self::assertSame(
            $enrollment->id->value,
            $f->service->register($student->id, $b->id, true)->id->value,
        );
        self::assertSame($before, $t->digest());
        self::assertSame("warn", $root->scheduleSummary()["mode"]);
    }
    public function testBackToBackMeetingsAndDifferentDatesAreAllowedWithoutAFalseConflict(): void
    {
        $itemsToCheck1 = [
                ["2026-09-01T17:00:00Z", "2026-09-01T18:00:00Z"],
                ["2026-09-02T16:30:00Z", "2026-09-02T17:30:00Z"],
            ];
        foreach ($itemsToCheck1 as $times) {
            $t = $this->fixture();
            [$student, $a, $b] = $this->pair($t);
            $f = $t->sis;
            $t->publish($b, [$times]);
            self::assertSame(
                "clear",
                $f->service->reviewSchedule($student->id, $b->id)["status"],
            );
            self::assertSame(
                "A",
                $f->service->register($student->id, $b->id, true)->status
                    ->value,
            );
        }
    }
    public function testUnpublishedPlansStayExplicitlyUncheckedAndKnownEmptyPlansAreDifferent(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $student = $f->student();
        $section = $f->section();
        $review = $f->service->reviewSchedule($student->id, $section->id);
        self::assertSame("not_fully_checked", $review["status"]);
        self::assertSame(1, $review["unpublished_count"]);
        $t->publish($section, []);
        self::assertSame(
            "clear",
            $f->service->reviewSchedule($student->id, $section->id)["status"],
        );
        $f->service->register($student->id, $f->section()->id, true);
        $t->publish($section, [
            ["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"],
        ]);
        $review = $f->service->reviewSchedule($student->id, $section->id);
        self::assertSame("not_fully_checked", $review["status"]);
        self::assertFalse($review["blocked"]);
        $f->service->register($student->id, $section->id, true);
    }
    public function testClosedOrRemovedEnrollmentsDoNotBlockAnotherSection(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $f->service->drop($student->id, $a->id);
        self::assertSame(
            "clear",
            $f->service->reviewSchedule($student->id, $b->id)["status"],
        );
        self::assertSame(
            "A",
            $f->service->register($student->id, $b->id, true)->status->value,
        );
    }
    public function testMissingOrChangedKnownPlanProjectionFailsInsteadOfClaimingNoConflict(): void
    {
        foreach (["missing", "hash"] as $change) {
            $t = $this->fixture();
            [$student, $a, $b] = $this->pair($t);
            $f = $t->sis;
            if ($change === "missing") {
                $f->sql->execute(
                    "DELETE FROM sis_section_meeting_plans WHERE id = ?",
                    [$a->id->value],
                );
            } else {
                $f->sql->update(
                    "sis_section_meeting_plans",
                    ["definition_hash" => str_repeat("a", 64)],
                    ["id" => $a->id->value],
                );
            }
            $before = $t->digest();
            try {
                $f->service->register($student->id, $b->id, true);
                self::fail("A broken known plan was ignored.");
            } catch (CorruptHistoryException) {
                self::assertSame($before, $t->digest());
            }
        }
    }
    public function testBatchRejectsOneConflictAndNewPublicationsInvalidateTheReviewedGroup(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $second = $f->student();
        $batch = $t->batch();
        $selection = new RegistrationBatchSelection(
            $b->id,
            $student->id,
            $second->id,
        );
        $review = $batch->review($selection);
        self::assertSame(
            1,
            count(
                array_filter(
                    $review["students"],
                    static fn(array $s): bool => $s["schedule"]["blocked"],
                ),
            ),
        );
        $before = $t->digest();
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
            );
            self::fail("A conflicting batch partially registered students.");
        } catch (ValidationException) {
            self::assertSame($before, $t->digest());
        }
        $t->policy()->save("warn", 0);
        $review = $batch->review($selection);
        $t->publish($b, [["2026-09-01T18:00:00Z", "2026-09-01T19:00:00Z"]]);
        $before = $t->digest();
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
            );
            self::fail("A new meeting plan escaped the review fingerprint.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $t->digest());
        }
    }
    public function testOverlapsAcrossDifferentTermsAreStillChecked(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $term = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => "Fictional session",
            "starts_on" => "2026-08-01",
            "ends_on" => "2026-12-31",
            "add_drop_ends_on" => "2026-08-15",
        ]);
        $f->sql->update(
            "sis_sections",
            ["term_id" => $term->value],
            ["id" => $b->id->value],
        );
        self::assertTrue(
            $f->service->reviewSchedule($student->id, $b->id)["blocked"],
        );
    }
    public function testTimetableOrPolicyChangesDuringFinalAuditRollBackAllRegistrationAndBillingFacts(): void
    {
        foreach (["plan", "policy"] as $change) {
            $t = $this->fixture();
            [$student, $a, $b] = $this->pair($t);
            $f = $t->sis;
            $t->policy()->save("warn", 0);
            $audit = $this->createStub(
                \Domain\Audit\Repository\AuditTrail::class,
            );
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $t,
                    $f,
                    $b,
                    $change
): void {
                    $f->audit->record($actor, $action, $subject);
                    if ($action === "registration.created") {
                        if ($change === "plan") {
                            $t->publish($b, []);
                        } else {
                            $f->sql->update(
                                "sis_registration_policy",
                                ["schedule_mode" => "block", "revision" => 2],
                                [
                                    "id" =>
                                        RegistrationScheduleRepository::POLICY_ID,
                                ],
                            );
                        }
                    }
                });
            $before = $t->digest();
            $policy = $t->policy()->current();
            try {
                $t->service($audit)->register($student->id, $b->id, true);
                self::fail("A changed timetable escaped the completion check.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $t->digest());
                self::assertSame($policy, $t->policy()->current());
            }
        }
    }
    public function testOversizedCandidateInventoryCannotBecomeAPartialClearAssessment(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $student = $f->student();
        $section = $f->section();
        $t->publish($section, [
            ["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"],
        ]);
        $records = $this->createStub(RegistrationScheduleRepository::class);
        $records
            ->method("policy")
            ->willReturn(["mode" => "block", "revision" => 0]);
        $records->method("enrollments")->willReturn(array_fill(0, 1000, []));
        $reader = new \Domain\Registration\Service\PublishedSectionMeetingPlanReader(
            new \Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository(
                $f->sql,
                $f->bookingSections(),
            ),
            EventStoreFixture::history($f->connection->queryBuilder()),
        );
        $this->expectException(ValidationException::class);
        new \Domain\Registration\Service\RegistrationScheduleService(
            $records,
            $reader,
        )->evaluate($student->id, $section->id);
    }

    public function testSelectedStudentPreviewRequiresOwnershipOrRegistrarAuthority(): void
    {
        $t = $this->fixture();
        [$student, $a, $b] = $this->pair($t);
        $f = $t->sis;
        $f->access->staff = false;
        $f->access->grants = [];
        $f->access->actor = $f->user();
        try {
            $f->service->reviewSchedule($student->id, $b->id);
            self::fail("Another account read a student schedule.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->actor = $f->admissions->person(
            $student->personId,
        )->ownerUserId;
        self::assertTrue(
            $f->service->reviewSchedule($student->id, $b->id)["blocked"],
        );
    }
    public function testPolicyRequiresItsIndependentGrantRejectsStaleChangesAndAddsNoRoleGrants(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $grants = $f->sql->rows("sis_access_role_permissions");
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        try {
            $t->policy()->save("warn", 0);
            self::fail("Registration duty changed institutional policy.");
        } catch (AccessDeniedException) {
            self::assertSame("block", $t->policy()->current()["mode"]);
        }
        $f->access->grants[] = "sis:registration:policy";
        self::assertSame("warn", $t->policy()->save("warn", 0)["mode"]);
        try {
            $t->policy()->save("block", 0);
            self::fail("A stale policy replaced a newer one.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $grants,
                $f->sql->rows("sis_access_role_permissions"),
            );
        }
        self::assertSame("warn", $t->policy()->current()["mode"]);
    }
}
