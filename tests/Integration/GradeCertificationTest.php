<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Gradebook\GradeCertificationHistory;
use Domain\Gradebook\ValueObject\GradeCertificationId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class GradeCertificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(faculty: $s->access->actor);
        $s->service->register($student->id, $section->id);
        $s->gradeScale([
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
        return [$s, $student, $section];
    }
    private function grade(
        SisFixture $s,
        EntityId $student,
        EntityId $section,
        string $code = "A",
    ): void {
        $enrollment = $s->registration->enrollment($student, $section);
        $credit = $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        $grade = new EntityId($s->sql->one("sis_grades", ["code" => $code])["id"]);
        $review = $s
            ->gradebook()
            ->reviewFinalGrade(
                $student,
                $section,
                $grade,
                (int) $credit["version"],
                reason: "Fictional documented grade decision.",
            );
        $s->gradebook()->commitFinalGrade($student, $section, $review["review_token"]);
    }
    private function confirm(
        SisFixture $s,
        EntityId $section,
        array $r,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): array {
        return $s
            ->gradeCertifications($audit)
            ->confirm(
                $section,
                new EntityId($r["operation_id"]),
                $r["revision"],
                $r["expected_version"],
                $r["reason"],
                $r["acknowledged_history_gaps"],
                $r["review_token"],
            );
    }
    public function testReadinessCertificationReplayAndLaterCorrectionRetainTheOriginalGrades(): void
    {
        [$s, $student, $section] = $this->fixture();
        $service = $s->gradeCertifications();
        $readiness = $service->readiness($section->id);
        self::assertFalse($readiness["ready"]);
        self::assertSame("missing_final_grade", $readiness["issues"][0]["status"]);
        $this->grade($s, $student->id, $section->id);
        self::assertTrue($service->readiness($section->id)["ready"]);
        $review = $service->review($section->id, "Fictional completed class review.", true);
        $business = $this->business($s);
        $result = $this->confirm($s, $section->id, $review);
        self::assertSame($business, $this->business($s));
        self::assertSame(1, $result["version"]);
        self::assertTrue($service->readiness($section->id)["latest"]["matches_current"]);
        $history = $service->listing($section->id);
        self::assertSame(1, $history["items"][0]["student_count"]);
        self::assertArrayNotHasKey("rows", $history["items"][0]);
        $original = $service->detail($section->id, new EntityId($result["operation_id"]));
        self::assertSame("A", $original["rows"][0]["grade_code"]);
        $root = GradeCertificationHistory::reconstituteFromEventStream(
            EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new GradeCertificationId($section->id->value),
            ),
        );
        self::assertSame(1, $root->playhead());
        self::assertArrayNotHasKey("current_name", $root->records()[0]["snapshot"]["rows"][0]);
        $this->grade($s, $student->id, $section->id, "B");
        self::assertFalse($service->readiness($section->id)["latest"]["matches_current"]);
        self::assertSame(
            $original,
            $service->detail($section->id, new EntityId($result["operation_id"])),
        );
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($result, $this->confirm($s, $section->id, $review));
    }
    private function business(SisFixture $s): array
    {
        $data = [];
        foreach (
            [
                "sis_enrollments",
                "sis_academic_credits",
                "sis_scores",
                "sis_account_entries",
                "sis_grade_change_requests",
            ] as $table
        ) {
            $data[$table] = $s->sql->rows($table);
        }
        return $data;
    }
    public function testHistoryGapsNeedAcknowledgementAndMissingGradesCannotBeCertified(): void
    {
        [$s, $student, $section] = $this->fixture();
        try {
            $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
            self::fail("Ungraded roster accepted.");
        } catch (ValidationException) {
            self::assertSame([], $s->gradeCertifications()->listing($section->id)["items"]);
        }
        $this->grade($s, $student->id, $section->id);
        self::assertTrue($s->gradeCertifications()->readiness($section->id)["history_gaps"]);
        $this->expectException(ValidationException::class);
        $s->gradeCertifications()->review($section->id, "Fictional class review.", false);
    }
    public function testChangedGradeRejectsReviewAndRollsBackAuditAndJournal(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $this->grade($s, $student->id, $section->id, "B");
        $events = $s->sql->rows("sis_history_events");
        $audit = $s->sql->rows("sis_audit");
        try {
            $this->confirm($s, $section->id, $r);
            self::fail("Stale grade accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame($audit, $s->sql->rows("sis_audit"));
        }
    }
    public function testDutyAndCurrentFacultyScopeAreRequiredAndArchiveHasSeparateDuties(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $s->access->staff = false;
        try {
            $s->gradeCertifications()->readiness($section->id);
            self::fail("Faculty lacked certification duty.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants = ["sis:gradebook:certify"];
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $this->confirm($s, $section->id, $r);
        self::assertCount(1, $s->gradeCertifications()->listing($section->id)["items"]);
        try {
            $s->gradeCertifications()->detail($section->id, new EntityId($r["operation_id"]));
            self::fail("Faculty reopened archived student facts.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->gradeCertifications()->readiness($section->id);
    }
    public function testNewRosterMemberAndConcurrentCertificationRequireAnotherReview(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $second = $s->student();
        $s->service->register($second->id, $section->id);
        try {
            $this->confirm($s, $section->id, $r);
            self::fail("Partial roster certified.");
        } catch (ValidationException) {
            self::assertSame(
                0,
                $s->gradeCertifications()->readiness($section->id)["expected_version"],
            );
        }
        $this->grade($s, $second->id, $section->id);
        $a = $s
            ->gradeCertifications()
            ->review($section->id, "Fictional first complete review.", true);
        $b = $s
            ->gradeCertifications()
            ->review($section->id, "Fictional second complete review.", true);
        $this->confirm($s, $section->id, $a);
        try {
            $this->confirm($s, $section->id, $b);
            self::fail("Concurrent class certification overtaken.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $s->gradeCertifications()->listing($section->id)["items"]);
        }
    }
    public function testPendingCorrectionBlocksCertificationUntilResolved(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $s->academicCredits()->saveGradeChangePolicy("approval", 0);
        $this->grade($s, $student->id, $section->id, "B");
        $data = $s->gradeCertifications()->readiness($section->id);
        self::assertFalse($data["ready"]);
        self::assertSame("pending_grade_change", $data["issues"][0]["status"]);
        self::assertSame("A", $data["rows"][0]["grade_code"]);
    }
    public function testAuditFailureAndLateRevocationRollBackTheCertification(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $events = $s->sql->rows("sis_history_events");
        $auditRows = $s->sql->rows("sis_audit");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(function (
                EntityId $actor,
                string $action,
                EntityId $id,
            ) use ($s): void {
                $s->audit->record($actor, $action, $id);
                $s->access->staff = false;
                $s->access->grants = [];
            });
        try {
            $this->confirm($s, $section->id, $r, $audit);
            self::fail("Revoked authority accepted.");
        } catch (AccessDeniedException) {
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame($auditRows, $s->sql->rows("sis_audit"));
        }
    }
    public function testExpiredReviewAndTamperedIntentDoNotAppend(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $bad = $r;
        $bad["reason"] = "Different unreviewed reason.";
        try {
            $this->confirm($s, $section->id, $bad);
            self::fail("Changed reason accepted.");
        } catch (ValidationException | AccessDeniedException | ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $this->confirm($s, $section->id, $r);
            self::fail("Expired review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->gradeCertifications()->listing($section->id)["items"]);
        }
    }
    public function testEmptyAndOversizedRostersNeverProducePartialCertificates(): void
    {
        $s = new SisFixture();
        $section = $s->section();
        self::assertFalse($s->gradeCertifications()->readiness($section->id)["ready"]);
        $roster = $this->createStub(\Domain\Gradebook\Repository\GradeCertificationRoster::class);
        $roster
            ->method("rows")
            ->willReturn(
                array_fill(0, 51, [
                    "id" => EntityId::generate()->value,
                    "student_id" => EntityId::generate()->value,
                ]),
            );
        $this->expectException(ValidationException::class);
        $s->gradeCertifications(roster: $roster)->readiness($section->id);
    }
    public function testEarlyDropIsExcludedAndWithdrawnStudentRequiresARecordedGrade(): void
    {
        [$s, $student, $section] = $this->fixture();
        $drop = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($drop["enrollment_id"]),
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        self::assertSame([], $s->gradeCertifications()->readiness($section->id)["rows"]);
        $s->service->register($student->id, $section->id);
        $s->clock->time = new \DateTimeImmutable("2026-08-20T12:00:00-07:00");
        $drop = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($drop["enrollment_id"]),
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        $data = $s->gradeCertifications()->readiness($section->id);
        self::assertCount(1, $data["rows"]);
        self::assertSame("W", $data["rows"][0]["status"]);
        self::assertFalse($data["ready"]);
    }
    public function testAuditTimeSourceChangeAndExpiredReviewRollBackAllSupportRows(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $events = $s->sql->rows("sis_history_events");
        $auditRows = $s->sql->rows("sis_audit");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(function (
                EntityId $actor,
                string $action,
                EntityId $id,
            ) use ($s): void {
                $s->audit->record($actor, $action, $id);
                $s->clock->time = $s->clock->time->modify("+21 minutes");
            });
        try {
            $this->confirm($s, $section->id, $r, $audit);
            self::fail("Expired review accepted after required audit.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame($auditRows, $s->sql->rows("sis_audit"));
        }
    }
    public function testLaterDatesAndWorkingScoresDoNotRewriteOrStalePostedGradeEvidence(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $this->confirm($s, $section->id, $r);
        $assignment = new \Domain\Gradebook\Assignment(
            EntityId::generate(),
            $section->id,
            "Fictional working quiz",
            1000,
            new \DateTimeImmutable("2026-08-15"),
        );
        $s->gradebook()->addAssignment($assignment);
        $s->gradebook()->score($assignment->id, $student->id, 700, 0);
        $s->clock->time = $s->clock->time->modify("+1 day");
        self::assertTrue(
            $s->gradeCertifications()->readiness($section->id)["latest"]["matches_current"],
        );
        self::assertSame(
            "A",
            $s->gradeCertifications()->detail($section->id, new EntityId($r["operation_id"]))[
                "rows"
            ][0]["grade_code"],
        );
    }
    public function testCertificationHistoryUsesAcceptedVersionsAndBoundedPages(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        for ($i = 1; $i <= 11; $i++) {
            $r = $s->gradeCertifications()->review($section->id, "Fictional review " . $i, true);
            self::assertSame($i, $this->confirm($s, $section->id, $r)["version"]);
        }
        $page = $s->gradeCertifications()->listing($section->id);
        self::assertCount(10, $page["items"]);
        self::assertSame(11, $page["items"][0]["version"]);
        self::assertSame(2, $page["next_before"]);
        $next = $s->gradeCertifications()->listing($section->id, $page["next_before"]);
        self::assertCount(1, $next["items"]);
        self::assertSame(1, $next["items"][0]["version"]);
        self::assertNull($next["next_before"]);
    }

    public function testMidnightRequiresAReviewForTheNewDateButAcceptedRetriesKeepTheirDate(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $s->clock->time = $s->clock->time->setTime(23, 59);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $s->clock->time = $s->clock->time->modify("+2 minutes");
        try {
            $this->confirm($s, $section->id, $r);
            self::fail("A review from another date was accepted.");
        } catch (ValidationException | AccessDeniedException | ConcurrentModificationException) {
            self::assertSame([], $s->gradeCertifications()->listing($section->id)["items"]);
        }
    }

    public function testWithdrawnFinalGradeCanBeCertifiedWithoutAwardingCredit(): void
    {
        $s = new SisFixture();
        $s->gradeScale([["code" => "W", "min_score_milli" => null, "max_score_milli" => null,
            "points_milli" => 0, "passes" => false, "counts_gpa" => false]]);
        $student = $s->student();
        $section = $s->section();
        $s->service->register($student->id, $section->id);
        $s->publishDropPolicy(
            $s->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([["through" => null, "grade_code" => "W"]]),
        );
        $s->clock->time = new \DateTimeImmutable("2026-08-20T12:00:00-07:00");
        $drop = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($drop["enrollment_id"]),
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"]
        );
        self::assertTrue($s->gradeCertifications()->readiness($section->id)["ready"]);
        $r = $s->gradeCertifications()->review($section->id, "Fictional withdrawn-grade review.", true);
        $before = $this->business($s);
        $this->confirm($s, $section->id, $r);
        $row = $s->gradeCertifications()->detail($section->id, new EntityId($r["operation_id"]))["rows"][0];
        self::assertSame("W", $row["status"]);
        self::assertSame("W", $row["grade_code"]);
        self::assertSame(0, $row["completed_milli"]);
        self::assertSame($before, $this->business($s));
    }

    public function testSourceChangeInsideRequiredAuditCannotBeAcceptedOrCommitted(): void
    {
        [$s, $student, $section] = $this->fixture();
        $this->grade($s, $student->id, $section->id);
        $r = $s->gradeCertifications()->review($section->id, "Fictional class review.", true);
        $before = $s->sql->rows("sis_courses");
        $events = $s->sql->rows("sis_history_events");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method("record")->willReturnCallback(function () use ($s, $section): void {
            $s->sql->update("sis_courses", ["title" => "Fictional changed label"], ["id" => $section->courseId->value]);
        });
        try {
            $this->confirm($s, $section->id, $r, $audit);
            self::fail("A changed source was committed after the required audit.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_courses"));
            self::assertSame($events, $s->sql->rows("sis_history_events"));
        }
    }
}
