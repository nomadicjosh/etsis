<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class GradeChangeApprovalTest extends TestCase
{
    private function fixture(bool $approval = true): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $enrollment = $f->service->register($student->id, $section->id, true);
        $ledger = $f->academicCredits();
        $choices = $ledger->choices($student->id, $section->id, $enrollment->id);
        $grades = array_column($choices["items"], "id", "code");
        $initial = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades["B"]),
            0,
        );
        $ledger->commit($student->id, $section->id, $initial["review_token"]);
        if ($approval) {
            $ledger->saveGradeChangePolicy("approval", 0);
        }
        return [$f, $ledger, $student, $section, $enrollment, $grades];
    }
    private function request(array $fixture): array
    {
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $review = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional recalculation supported by corrected marking.",
        );
        $receipt = $ledger->commit($student->id, $section->id, $review["review_token"]);
        return [new EntityId($receipt["operation_id"]), $review, $receipt];
    }
    public function testRequestKeepsOriginalGradeUntilAnotherRegistrarApprovesAndRetriesRemainOriginal(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $before = $f->sql->rows("sis_academic_credits");
        [$id, $review, $submitted] = $this->request($fixture);
        self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        $encrypted = $f->sql->one("sis_grade_change_requests", ["id" => $id->value]);
        self::assertStringNotContainsString("Fictional recalculation", $encrypted["details"]);
        self::assertSame($submitted, $ledger->commit($student->id, $section->id, $review["review_token"]));
        self::assertCount(1, $ledger->gradeChangePage()["items"]);
        self::assertTrue($ledger->gradeChangeRequest($id)["can_cancel"]);
        self::assertFalse($ledger->gradeChangeRequest($id)["can_approve"]);
        $f->access->actor = $f->user();
        $approval = $ledger->reviewGradeChange($id, "approved", "Fictional independent record review.");
        $receipt = $ledger->resolveGradeChange($id, $approval["review_token"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame($grades["A"], $credit["grade_id"]);
        self::assertSame(12000, $credit["grade_points_milli"]);
        self::assertSame(2, $credit["version"]);
        self::assertSame("approved", $ledger->gradeChangeRequest($id)["status"]);
        self::assertSame([], $ledger->gradeChangePage()["items"]);
        $f->clock->time = $f->clock->time->modify("+1 hour");
        self::assertSame($receipt, $ledger->resolveGradeChange($id, $approval["review_token"]));
        self::assertSame(2, $f->sql->rows("sis_academic_credits")[0]["version"]);
    }
    public function testEvenSuperCannotApproveTheirOwnRequest(): void
    {
        $fixture = $this->fixture();
        [, $ledger] = $fixture;
        [$id] = $this->request($fixture);
        $this->expectException(AccessDeniedException::class);
        $ledger->reviewGradeChange($id, "approved", "Self approval must not be accepted.");
    }
    public function testDirectCorrectionRemainsDefaultAndPolicyChangesInvalidatePendingReview(): void
    {
        $fixture = $this->fixture(false);
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        self::assertSame("direct", $ledger->gradeChangePolicy()["mode"]);
        $review = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional correction.",
        );
        $before = $f->sql->rows("sis_history_events");
        $ledger->saveGradeChangePolicy("approval", 0);
        try {
            $ledger->commit($student->id, $section->id, $review["review_token"]);
            self::fail("A policy change must invalidate a previously direct correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_grade_change_requests"));
        }
        $ledger->saveGradeChangePolicy("direct", 1);
        $review = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional corrected marking.",
        );
        $ledger->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame($grades["A"], $f->sql->rows("sis_academic_credits")[0]["grade_id"]);
    }
    public function testCancellationAndRejectionRetainTheGradeAndPermitAFreshRequest(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger] = $fixture;
        $requester = $f->access->actor;
        $before = $f->sql->rows("sis_academic_credits");
        [$id] = $this->request($fixture);
        $review = $ledger->reviewGradeChange($id, "cancelled", "Fictional request no longer needed.");
        $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame("cancelled", $ledger->gradeChangeRequest($id)["status"]);
        [$next] = $this->request($fixture);
        $f->access->actor = $f->user();
        $review = $ledger->reviewGradeChange(
            $next,
            "rejected",
            "Fictional supporting evidence needs correction.",
        );
        $ledger->resolveGradeChange($next, $review["review_token"]);
        self::assertSame("rejected", $ledger->gradeChangeRequest($next)["status"]);
        self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        $f->access->actor = $requester;
        [$fresh] = $this->request($fixture);
        self::assertNotSame($id->value, $fresh->value);
    }
    public function testProposedGradesAndReasonsAreNotDisclosedInOrdinaryHistoryOrWorklist(): void
    {
        $fixture = $this->fixture();
        [, $ledger, $student, $section, $enrollment] = $fixture;
        $this->request($fixture);
        $page = $ledger->history($student->id, $section->id, $enrollment->id);
        $requested = array_values(
            array_filter(
                $page["items"],
                static fn(array $row): bool => $row["action"] === "correction_requested",
            ),
        )[0];
        self::assertNull($requested["grade_code"]);
        self::assertNull($requested["grade_points_milli"]);
        self::assertStringNotContainsString(
            "Fictional recalculation",
            json_encode($page, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            "Fictional recalculation",
            json_encode($ledger->gradeChangePage(), JSON_THROW_ON_ERROR),
        );
    }
    public function testProjectionDriftPreventsApprovalAndOrdinaryGradeReads(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment] = $fixture;
        [$id] = $this->request($fixture);
        $f->sql->update("sis_grade_change_requests", ["status" => "approved"], ["id" => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $ledger->choices($student->id, $section->id, $enrollment->id);
    }
    public function testExpiryAndActorBoundReviewsCannotApprove(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger] = $fixture;
        [$id] = $this->request($fixture);
        $f->access->actor = $f->user();
        $review = $ledger->reviewGradeChange($id, "approved", "Fictional independent review.");
        $before = [$f->sql->rows("sis_history_events"), $f->sql->rows("sis_academic_credits")];
        $f->clock->time = $f->clock->time->modify("+20 minutes");
        try {
            $ledger->resolveGradeChange($id, $review["review_token"]);
            self::fail("Expired review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_academic_credits"),
            ]);
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $ledger->resolveGradeChange($id, $review["review_token"]);
    }

    public function testChangedAcademicFactsMakeApprovalStaleButStillAllowRejection(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment] = $fixture;
        [$id] = $this->request($fixture);
        $dates = $f->academicDates();
        $review = $dates->review(
            $student->id,
            $section->id,
            $enrollment->id,
            $f->clock->time->format("Y-m-d"),
            1,
            "Fictional completion-date evidence.",
        );
        $dates->commit($student->id, $section->id, $review["review_token"]);
        $f->access->actor = $f->user();
        self::assertTrue($ledger->gradeChangeRequest($id)["stale"]);
        try {
            $ledger->reviewGradeChange($id, "approved", "A stale proposal must not be accepted.");
            self::fail("Changed academic facts were ignored.");
        } catch (ConcurrentModificationException) {
            self::assertSame(2, $f->sql->rows("sis_academic_credits")[0]["version"]);
        }
        $review = $ledger->reviewGradeChange($id, "rejected", "Request needs a fresh academic review.");
        $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame("rejected", $ledger->gradeChangeRequest($id)["status"]);
    }

    public function testDisablingApprovalDoesNotBypassAnExistingRequestOrDuplicateRequest(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        [$id] = $this->request($fixture);
        $ledger->saveGradeChangePolicy("direct", 1);
        try {
            $ledger->review(
                $student->id,
                $section->id,
                $enrollment->id,
                new EntityId($grades["A"]),
                1,
                reason: "Still pending.",
            );
            self::fail("A pending request was bypassed.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $ledger->gradeChangePage()["items"]);
        }
        $f->access->actor = $f->user();
        $review = $ledger->reviewGradeChange(
            $id,
            "approved",
            "Reviewed under the original requested approval.",
        );
        $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame($grades["A"], $f->sql->rows("sis_academic_credits")[0]["grade_id"]);
    }

    public function testApprovalRequiresIndependentPermissionAndFailureRollsBackEveryCoupledWrite(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger] = $fixture;
        [$id] = $this->request($fixture);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read", "sis:gradebook:amend"];
        try {
            $ledger->reviewGradeChange($id, "approved", "Needs the separate approval duty.");
            self::fail("Amendment permission granted independent approval.");
        } catch (AccessDeniedException) {
            self::assertSame("pending", $ledger->gradeChangeRequest($id)["status"]);
        }
        $f->access->grants[] = "sis:gradebook:approve";
        $review = $ledger->reviewGradeChange($id, "approved", "Fictional independent approval.");
        $before = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_history_operations"),
            $f->sql->rows("sis_academic_credits"),
            $f->sql->rows("sis_grade_change_requests"),
        ];
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional audit failure."));
        try {
            $f->academicCredits($audit)->resolveGradeChange($id, $review["review_token"]);
            self::fail("Failed auditing was accepted.");
        } catch (\RuntimeException $error) {
            self::assertSame("Fictional audit failure.", $error->getMessage());
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_history_operations"),
                $f->sql->rows("sis_academic_credits"),
                $f->sql->rows("sis_grade_change_requests"),
            ]);
        }
    }

    public function testReadAndPolicyPermissionsRemainSeparateFromStudentOwnershipAndGradeManagement(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger] = $fixture;
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:manage", "sis:student:read"];
        try {
            $ledger->gradeChangePage();
            self::fail("Ordinary teaching authority read protected grade-change cases.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_grade_change_requests"));
        }
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $ledger->saveGradeChangePolicy("direct", 1);
    }

    public function testAuthorityLossAndPolicyDriftAtCompletionRollBackTheRequestOrApproval(): void
    {
        $fixture = $this->fixture();
        [$f, $ledger, $student, $section, $enrollment, $grades] = $fixture;
        $review = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional proposal.",
        );
        $before = [$f->sql->rows("sis_history_events"), $f->sql->rows("sis_grade_change_policy")];
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f): void {
            $f->sql->update(
                "sis_grade_change_policy",
                ["mode" => "direct", "revision" => 2],
                ["id" => \Domain\AcademicRecord\Repository\GradeChangeRepository::POLICY_ID],
            );
        });
        try {
            $f->academicCredits($audit)->commit($student->id, $section->id, $review["review_token"]);
            self::fail("Policy drift during request submission was ignored.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_grade_change_policy"),
            ]);
            self::assertSame([], $f->sql->rows("sis_grade_change_requests"));
        }
        [$id] = $this->request($fixture);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
        $review = $ledger->reviewGradeChange($id, "approved", "Fictional independent approval.");
        $before = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_academic_credits"),
            $f->sql->rows("sis_grade_change_requests"),
        ];
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f): void {
            $f->access->grants = [];
        });
        try {
            $f->academicCredits($audit)->resolveGradeChange($id, $review["review_token"]);
            self::fail("Lost authority was ignored.");
        } catch (AccessDeniedException) {
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_academic_credits"),
                $f->sql->rows("sis_grade_change_requests"),
            ]);
        }
    }

    public function testAnOlderGradedRecordIsObservedWithoutChangingItBeforeApproval(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(faculty: $f->access->actor);
        $a = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "A", 4000, true, true);
        $b = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "B", 3000, true, true);
        $f->legacyGrade($a);
        $f->legacyGrade($b);
        $enrollment = EntityId::generate();
        $credit = EntityId::generate();
        $f->sql->insert("sis_enrollments", [
            "id" => $enrollment->value,
            "student_id" => $student->id->value,
            "section_id" => $section->id->value,
            "term_id" => $f->term->value,
            "status" => "A",
            "registered_at" => "2026-08-01T17:00:00.000000Z",
            "status_at" => "2026-08-01T17:00:00.000000Z",
            "version" => 0,
        ]);
        $f->sql->insert("sis_academic_credits", [
            "id" => $credit->value,
            "student_id" => $student->id->value,
            "course_id" => $section->courseId->value,
            "term_id" => $f->term->value,
            "source_id" => $enrollment->value,
            "credit_type" => "institutional",
            "course_code" => "TEST.101",
            "course_title" => "Fictional earlier course",
            "attempted_milli" => 3000,
            "completed_milli" => 3000,
            "grade_points_milli" => 9000,
            "grade_id" => $b->id->value,
            "status" => "A",
            "version" => 4,
        ]);
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $before = $f->sql->rows("sis_academic_credits");
        $review = $ledger->review(
            $student->id,
            $section->id,
            $enrollment,
            $a->id,
            4,
            reason: "Fictional correction to an earlier record.",
        );
        $receipt = $ledger->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        self::assertSame($credit->value, $f->sql->rows("sis_academic_credit_streams")[0]["id"]);
        self::assertTrue($ledger->auditFacts($credit, $student->id)["earlier_history_unknown"]);
        $f->access->actor = $f->user();
        $id = new EntityId($receipt["operation_id"]);
        $review = $ledger->reviewGradeChange(
            $id,
            "approved",
            "Fictional independent review of retained source evidence.",
        );
        $ledger->resolveGradeChange($id, $review["review_token"]);
        self::assertSame($a->id->value, $f->sql->rows("sis_academic_credits")[0]["grade_id"]);
        self::assertSame(5, $f->sql->rows("sis_academic_credits")[0]["version"]);
    }
}
