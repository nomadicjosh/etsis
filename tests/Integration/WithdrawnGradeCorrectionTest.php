<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WithdrawnGradeCorrectionTest extends TestCase
{
    private function fixture(bool $earlier = false): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "D",
                "min_score_milli" => 65000,
                "max_score_milli" => 69999,
                "points_milli" => 1000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "W",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ],
        ]);
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
        if ($earlier) {
            $ids = array_column($f->sql->rows("sis_grades"), "id", "code");
            $id = EntityId::generate();
            $f->sql->insert("sis_enrollments", [
                "id" => $id->value,
                "student_id" => $student->id->value,
                "section_id" => $section->id->value,
                "term_id" => $f->term->value,
                "status" => "W",
                "registered_at" => "2026-08-01T17:00:00.000000Z",
                "status_at" => "2026-09-02T17:00:00.000000Z",
                "version" => 3,
            ]);
            $f->sql->insert("sis_academic_credits", [
                "id" => EntityId::generate()->value,
                "student_id" => $student->id->value,
                "course_id" => $section->courseId->value,
                "term_id" => $f->term->value,
                "source_id" => $id->value,
                "credit_type" => "institutional",
                "course_code" => "TEST.101",
                "course_title" => "Fictional older course",
                "attempted_milli" => 3000,
                "completed_milli" => 0,
                "grade_points_milli" => 0,
                "grade_id" => $ids["W"],
                "status" => "W",
                "version" => 4,
            ]);
            $enrollment = $f->registration->enrollment($student->id, $section->id);
        } else {
            // Registration occurs while its normal window is open; the later command is the withdrawal.
            $f->clock->time = new DateTimeImmutable("2026-08-10T12:00:00-07:00");
            $enrollment = $f->service->register($student->id, $section->id, true);
            $f->publishDropPolicy($f->term, -1, new DropWindows([["through" => null, "grade_code" => "W"]]));
            $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
            $f->service->drop($student->id, $section->id);
        }
        $ledger = $f->academicCredits();
        $choices = $ledger->choices($student->id, $section->id, $enrollment->id);
        $grades = array_column($choices["items"], "id", "code");
        return [$f, $ledger, $student, $section, $enrollment, $grades];
    }

    private function review(array $fixture, string $grade = "D"): array
    {
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $credit = $f->sql->rows("sis_academic_credits")[0];
        return $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades[$grade]),
            $credit["version"],
            reason: "Fictional review establishes the correct late-withdrawal grade.",
        );
    }

    public function testDirectCorrectionKeepsWithdrawalSeatChargePursuitAndZeroEarnedCredit(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $before = [
            $f->financialEvents(),
            $f->sql->rows("sis_enrollments"),
            $f->sql->rows("sis_work_pursuit_scopes"),
        ];
        $events = $f->sql->rows("sis_history_events");
        $review = $this->review($fixture);
        self::assertTrue($review["withdrawal_grade_correction"]);
        $receipt = $ledger->commit($student->id, $section->id, $review["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame("W", $credit["status"]);
        self::assertSame(0, $credit["completed_milli"]);
        self::assertSame(3000, $credit["grade_points_milli"]);
        self::assertSame($grades["D"], $credit["grade_id"]);
        self::assertSame($before, [
            $f->financialEvents(),
            $f->sql->rows("sis_enrollments"),
            $f->sql->rows("sis_work_pursuit_scopes"),
        ]);
        self::assertSame($events, array_slice($f->sql->rows("sis_history_events"), 0, count($events)));
        $inputs = $f->inputVerifier()->verify($student->id, new HistoryReadBudget());
        self::assertSame("W", $inputs->items[0]["status"]);
        self::assertSame(0, $inputs->items[0]["completed_milli"]);
        $target = $f->section(20, 0);
        self::assertFalse($f->prerequisitesMet($student->id, $target->courseId, [$section->courseId->value]));
        $f->clock->time = $f->clock->time->modify("+1 hour");
        self::assertSame($receipt, $ledger->commit($student->id, $section->id, $review["review_token"]));
    }

    public function testPassingGradeDefinitionNeverReopensOrAwardsWithdrawnCourseCredit(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section] = $fixture;
        $review = $this->review($fixture, "A");
        $ledger->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame(0, $f->sql->rows("sis_academic_credits")[0]["completed_milli"]);
        self::assertSame(12000, $f->sql->rows("sis_academic_credits")[0]["grade_points_milli"]);
        self::assertSame("W", $f->sql->rows("sis_enrollments")[0]["status"]);
    }

    public function testExtraClosedRecordDutyIsRequiredForChoicesAndEveryConfirmation(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment] = $fixture;
        $review = $this->review($fixture);
        $before = $f->sql->rows("sis_history_events");
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read", "sis:gradebook:amend"];
        try {
            $ledger->choices($student->id, $section->id, $enrollment->id);
            self::fail("Amendment authority implied closed-record correction.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
        $this->expectException(AccessDeniedException::class);
        $ledger->commit($student->id, $section->id, $review["review_token"]);
    }

    public function testSecondPersonApprovalAlsoRequiresClosedRecordDutyAndKeepsCharge(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $ledger->saveGradeChangePolicy("approval", 0);
        $review = $this->review($fixture);
        $before = [$f->sql->rows("sis_academic_credits"), $f->financialEvents()];
        $submitted = $ledger->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame($before, [$f->sql->rows("sis_academic_credits"), $f->financialEvents()]);
        $id = new EntityId($submitted["operation_id"]);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
        self::assertFalse($ledger->gradeChangeRequest($id)["can_approve"]);
        self::assertTrue($ledger->gradeChangeRequest($id)["can_reject"]);
        try {
            $ledger->reviewGradeChange($id, "approved", "Fictional independent review.");
            self::fail("Approval authority implied closed-record correction.");
        } catch (AccessDeniedException) {
            self::assertSame($before[0], $f->sql->rows("sis_academic_credits"));
        }
        $f->access->grants[] = "sis:gradebook:closed";
        $review = $ledger->reviewGradeChange(
            $id,
            "approved",
            "Fictional independent withdrawn-grade correction.",
        );
        $receipt = $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame($grades["D"], $f->sql->rows("sis_academic_credits")[0]["grade_id"]);
        self::assertSame(0, $f->sql->rows("sis_academic_credits")[0]["completed_milli"]);
        self::assertSame($before[1], $f->financialEvents());
        self::assertSame($receipt, $ledger->resolveGradeChange($id, $review["review_token"]));
    }

    public function testRequiredAuditFailureAndPermissionLossRollBackGradeEventsAndProjection(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section] = $fixture;
        $review = $this->review($fixture);
        $before = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_history_operations"),
            $f->sql->rows("sis_academic_credits"),
        ];
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional audit failure."));
        try {
            $f->academicCredits($audit)->commit($student->id, $section->id, $review["review_token"]);
            self::fail("The audit failure was ignored.");
        } catch (\RuntimeException $error) {
            self::assertSame("Fictional audit failure.", $error->getMessage());
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_history_operations"),
                $f->sql->rows("sis_academic_credits"),
            ]);
        }
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:closed"];
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f): void {
            $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        });
        try {
            $f->academicCredits($audit)->commit($student->id, $section->id, $review["review_token"]);
            self::fail("Permission loss was ignored.");
        } catch (AccessDeniedException) {
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_history_operations"),
                $f->sql->rows("sis_academic_credits"),
            ]);
        }
    }

    public function testOlderWithdrawalObservesKnownFactsWithoutInventingEarlierActions(): void
    {
        $fixture = $this->fixture(earlier: true);
        [$f, $ledger, $student, $section] = $fixture;
        $enrollments = $f->sql->rows("sis_enrollments");
        $review = $this->review($fixture);
        self::assertTrue($review["earlier_history_unknown"]);
        $receipt = $ledger->commit($student->id, $section->id, $review["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame(5, $credit["version"]);
        self::assertSame("W", $credit["status"]);
        self::assertSame(0, $credit["completed_milli"]);
        self::assertSame($enrollments, $f->sql->rows("sis_enrollments"));
        self::assertSame(2, $receipt["last_version"]);
        self::assertTrue(
            $ledger->auditFacts(new EntityId($credit["id"]), $student->id)["earlier_history_unknown"],
        );
    }

    public function testAnEarlyRemovedDropCannotBeRecreatedByTheGradeEditor(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $f->service->drop($student->id, $section->id);
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->academicCredits()->choices($student->id, $section->id, $enrollment->id);
            self::fail("An early removed record was opened for grading.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame([], $f->sql->rows("sis_academic_credits"));
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testOldConfirmationCannotOverwriteALaterWithdrawnGradeAndStaleReviewIsRejected(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section] = $fixture;
        $original = $this->review($fixture);
        $stale = $this->review($fixture, "A");
        $receipt = $ledger->commit($student->id, $section->id, $original["review_token"]);
        try {
            $ledger->commit($student->id, $section->id, $stale["review_token"]);
            self::fail("A stale grade review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(3000, $f->sql->rows("sis_academic_credits")[0]["grade_points_milli"]);
        }
        $later = $this->review($fixture, "A");
        $ledger->commit($student->id, $section->id, $later["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame($receipt, $ledger->commit($student->id, $section->id, $original["review_token"]));
        self::assertSame(12000, $f->sql->rows("sis_academic_credits")[0]["grade_points_milli"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testOlderWithdrawnRecordCanBeIndependentlyApprovedWithoutInventingHistory(): void
    {
        $fixture = $this->fixture(earlier: true);
        [$f, $ledger, $student, $section] = $fixture;
        $ledger->saveGradeChangePolicy("approval", 0);
        $original = $f->sql->rows("sis_academic_credits");
        $review = $this->review($fixture);
        $receipt = $ledger->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame($original, $f->sql->rows("sis_academic_credits"));
        $f->access->actor = $f->user();
        $id = new EntityId($receipt["operation_id"]);
        $review = $ledger->reviewGradeChange(
            $id,
            "approved",
            "Fictional review of retained earlier evidence.",
        );
        $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame("W", $f->sql->rows("sis_academic_credits")[0]["status"]);
        self::assertSame(0, $f->sql->rows("sis_academic_credits")[0]["completed_milli"]);
        self::assertTrue(
            $ledger->auditFacts(new EntityId($original[0]["id"]), $student->id)["earlier_history_unknown"],
        );
    }

    public function testUnrecordedWithdrawalGradeRemainsUnknownInsteadOfAssumingADefinition(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
        $f->service->drop($student->id, $section->id);
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->academicCredits()->choices($student->id, $section->id, $enrollment->id);
            self::fail("An unknown earlier grade was silently assigned a definition.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertNull($f->sql->rows("sis_academic_credits")[0]["grade_id"]);
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }
}
