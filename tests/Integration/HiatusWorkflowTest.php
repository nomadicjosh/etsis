<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/HiatusFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\GradeDefinition;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusImpact;
use Domain\Student\ValueObject\HiatusPolicy;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use PHPUnit\Framework\TestCase;

final class HiatusWorkflowTest extends TestCase
{
    private function dates(string $start = "2026-08-20", ?string $end = null): DateRange
    {
        return new DateRange(
            new DateTimeImmutable($start),
            $end === null ? null : new DateTimeImmutable($end),
        );
    }

    public function testReviewedWithdrawalAppliesOneEffectiveDateAndActivityChangeWithoutRefundingCharges(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $a = $f->section(20, 1250);
        $b = $f->section(20, 2250);
        $f->service->register($student->id, $a->id);
        $f->service->register($student->id, $b->id);
        $events = $f->financialEvents();
        $f->clock->time = new DateTimeImmutable("2026-08-25T10:00:00-07:00");
        $workflow = $h->workflow();
        $dates = $this->dates();
        $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
        self::assertTrue($review["defaults"]["deactivate"]);
        self::assertCount(2, $review["defaults"]["section_ids"]);
        $hiatus = new StudentHiatus(
            new EntityId($review["operation_id"]),
            $student->id,
            HiatusCode::Withdrawal,
            $dates,
        );
        $impact = new HiatusImpact(true, $review["revision"], [], $a->id, $b->id);
        $result = $workflow->commit($hiatus, "Fictional private withdrawal comment", $impact);
        self::assertCount(2, $result["impact"]["course_changes"]);
        self::assertFalse($f->admissions->student($student->id)->active);
        foreach ([$a, $b] as $section) {
            $enrollment = $f->registration->enrollment($student->id, $section->id);
            self::assertSame("W", $enrollment->status->value);
            self::assertSame(
                "2026-08-20",
                $enrollment->statusAt->setTimezone($f->clock->time->getTimezone())->format("Y-m-d"),
            );
            $credit = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
            self::assertSame(3000, $credit["attempted_milli"]);
            self::assertSame(0, $credit["completed_milli"]);
        }
        self::assertSame($events, $f->financialEvents());
        self::assertFalse($result["impact"]["automatic_refund"]);
        self::assertSame(
            $result,
            $workflow->commit($hiatus, "Fictional private withdrawal comment", $impact),
        );
        self::assertCount(1, $f->sql->rows("sis_student_hiatuses"));
        self::assertCount(1, $f->sql->rows("sis_student_hiatus_actions"));
        $raw = json_encode($f->sql->rows("sis_student_hiatus_actions"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional private withdrawal comment", $raw);
        self::assertSame($result["impact"], $h->history->view($student->id, $hiatus->id)["impact"]);
    }

    public function testStaffCanKeepStudentActiveAndKeepUnselectedCourses(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $a = $f->section(20, 0);
        $b = $f->section(20, 0);
        $f->service->register($student->id, $a->id);
        $f->service->register($student->id, $b->id);
        $workflow = $h->workflow();
        $dates = $this->dates("2026-08-10");
        $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
        $result = $workflow->commit(
            new StudentHiatus(
                new EntityId($review["operation_id"]),
                $student->id,
                HiatusCode::Withdrawal,
                $dates,
            ),
            "Fictional limited review",
            new HiatusImpact(false, $review["revision"], [], $a->id),
        );
        self::assertTrue($f->admissions->student($student->id)->active);
        self::assertNull($f->registration->enrollment($student->id, $a->id));
        self::assertSame("N", $f->registration->enrollment($student->id, $b->id)->status->value);
        self::assertCount(1, $result["impact"]["course_changes"]);
        self::assertFalse($result["impact"]["student_activity_changed"]);
    }

    public function testDefaultsAreConfigurableAndOtherHiatusTypesDoNotAssumeWithdrawal(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id);
        $cases1 = [HiatusCode::Leave, HiatusCode::StudyAbroad, HiatusCode::Illness, HiatusCode::Dismissal];
        foreach ($cases1 as $code) {
            $review = $h->workflow()->review($student->id, $code, $this->dates("2026-08-10"));
            self::assertSame(["deactivate" => false, "section_ids" => []], $review["defaults"]);
        }
        $custom = new HiatusPolicy([
            "W" => ["deactivate" => false, "courses" => false],
            "LOA" => ["deactivate" => true, "courses" => true],
        ]);
        self::assertFalse(
            $h
                ->workflow(policy: $custom)
                ->review($student->id, HiatusCode::Withdrawal, $this->dates("2026-08-10"))["defaults"][
                "deactivate"
            ],
        );
        self::assertTrue(
            $h
                ->workflow(policy: $custom)
                ->review($student->id, HiatusCode::Leave, $this->dates("2026-08-10"))["defaults"][
                "deactivate"
            ],
        );
    }

    public function testFutureDatedHistoryCannotApplyImmediateChanges(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id);
        $dates = $this->dates("2026-09-01");
        $workflow = $h->workflow();
        $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
        self::assertFalse($review["effective_now"]);
        self::assertSame([], $review["defaults"]["section_ids"]);
        $hiatus = new StudentHiatus(
            new EntityId($review["operation_id"]),
            $student->id,
            HiatusCode::Withdrawal,
            $dates,
        );
        try {
            $workflow->commit(
                $hiatus,
                "Fictional future case",
                new HiatusImpact(true, $review["revision"], []),
            );
            self::fail("A future date changed current activity.");
        } catch (ValidationException) {
            self::assertTrue($f->admissions->student($student->id)->active);
        }
        $workflow->commit($hiatus, "Fictional future case", new HiatusImpact(false, $review["revision"], []));
        self::assertSame("scheduled", $h->history->view($student->id, $hiatus->id)["state"]);
        self::assertSame("N", $f->registration->enrollment($student->id, $section->id)->status->value);
    }

    public function testPostedGradesAreProtectedAndNeverReturnedToRegistrationOnlyStaff(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id);
        $grades = new SqlGradebookRepository($f->sql);
        $grade = new GradeDefinition(EntityId::generate(), "A", 4000, true, true);
        $f->legacyGrade($grade);
        new GradebookService(
            $grades,
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->academicCredits(),
        )->finalGrade($student->id, $section->id, $grade->id, 0);
        $before = $f->sql->rows("sis_academic_credits");
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $workflow = $h->workflow();
        $dates = $this->dates("2026-08-10");
        $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
        self::assertFalse($review["courses"][0]["eligible"]);
        self::assertArrayNotHasKey("grade_id", $review["courses"][0]);
        self::assertArrayNotHasKey("completed_milli", $review["courses"][0]);
        self::assertArrayNotHasKey("grade_points_milli", $review["courses"][0]);
        self::assertStringNotContainsString($grade->id->value, json_encode($review, JSON_THROW_ON_ERROR));
        try {
            $workflow->commit(
                new StudentHiatus(
                    new EntityId($review["operation_id"]),
                    $student->id,
                    HiatusCode::Withdrawal,
                    $dates,
                ),
                "Fictional protected grade",
                new HiatusImpact(true, $review["revision"], [], $section->id),
            );
            self::fail("A posted grade was wiped by withdrawal.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
            self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
            self::assertTrue($f->admissions->student($student->id)->active);
        }
    }

    public function testConcurrentActivityIdentityAndDeadlineChangesInvalidateReview(): void
    {
        foreach (["activity", "identity", "deadline"] as $change) {
            $h = new HiatusFixture();
            $f = $h->sis;
            $student = $f->student();
            $section = $f->section(20, 0);
            $f->service->register($student->id, $section->id);
            $workflow = $h->workflow();
            $dates = $this->dates("2026-08-10");
            $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
            match ($change) {
                "activity" => $f->sql->update("sis_students", ["active" => 0], ["id" => $student->id->value]),
                "identity" => $f->sql->update(
                    "sis_person_identifiers",
                    ["institutional_id" => "P-CHANGED"],
                    ["person_id" => $student->personId->value],
                ),
                "deadline" => $f->sql->update(
                    "sis_terms",
                    ["add_drop_ends_on" => "2026-08-05"],
                    ["id" => $f->term->value],
                ),
            };
            try {
                $workflow->commit(
                    new StudentHiatus(
                        new EntityId($review["operation_id"]),
                        $student->id,
                        HiatusCode::Withdrawal,
                        $dates,
                    ),
                    "Fictional stale review",
                    new HiatusImpact(true, $review["revision"], [], $section->id),
                );
                self::fail("A changed " . $change . " was not reviewed again.");
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
                self::assertSame(
                    "N",
                    $f->registration->enrollment($student->id, $section->id)->status->value,
                );
            }
        }
    }

    public function testALateAuditFailureRollsBackTheWholeGuidedCase(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $credits = $f->sql->rows("sis_academic_credits");
        $events = $f->sql->rows("sis_history_events");
        $beforeAudit = $f->sql->rows("sis_audit");
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willReturnCallback(static function (
                EntityId $actor,
                string $action,
                EntityId $subject,
            ) use ($f): void {
                if ($action === "student.activity_updated") {
                    throw new RuntimeException("Fictional late audit failure");
                }
                $f->audit->record($actor, $action, $subject);
            });
        $workflow = $h->workflow($audit);
        $dates = $this->dates("2026-08-10");
        $review = $workflow->review($student->id, HiatusCode::Withdrawal, $dates);
        try {
            $workflow->commit(
                new StudentHiatus(
                    new EntityId($review["operation_id"]),
                    $student->id,
                    HiatusCode::Withdrawal,
                    $dates,
                ),
                "Fictional rollback",
                new HiatusImpact(true, $review["revision"], [], $section->id),
            );
            self::fail("A partially audited workflow committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
            self::assertSame([], $f->sql->rows("sis_student_hiatus_actions"));
            self::assertTrue($f->admissions->student($student->id)->active);
            self::assertSame("N", $f->registration->enrollment($student->id, $section->id)->status->value);
            self::assertSame($credits, $f->sql->rows("sis_academic_credits"));
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame($beforeAudit, $f->sql->rows("sis_audit"));
        }
    }

    public function testOwnershipOfAStudentRecordDoesNotAuthorizeStaffCaseWork(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $h->workflow()->review($student->id, HiatusCode::Withdrawal, $this->dates());
    }
}
