<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class WithdrawnCreditReconciliationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(
        string $gradeCode = "D",
        int $earned = 1000,
        int $points = 9999,
        bool $missingGrade = false,
    ): array {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "D",
                "min_score_milli" => 0,
                "max_score_milli" => 89999,
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
        $grades = array_column($f->sql->rows("sis_grades"), "id", "code");
        $student = $f->student($f->user())->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        $course = $f->registration->section($section)->courseId;
        $enrollment = EntityId::generate();
        $credit = EntityId::generate();
        $f->sql->insert("sis_enrollments", [
            "id" => $enrollment->value,
            "student_id" => $student->value,
            "section_id" => $section->value,
            "term_id" => $f->term->value,
            "status" => "W",
            "registered_at" => "2026-08-01T17:00:00Z",
            "status_at" => "2026-09-02T17:00:00Z",
            "version" => 1,
        ]);
        $f->sql->insert("sis_academic_credits", [
            "id" => $credit->value,
            "student_id" => $student->value,
            "source_id" => $enrollment->value,
            "course_id" => $course->value,
            "term_id" => $f->term->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Fictional withdrawn course",
            "attempted_milli" => 3000,
            "completed_milli" => $earned,
            "grade_points_milli" => $points,
            "grade_id" => $missingGrade ? null : $grades[$gradeCode],
            "status" => "W",
            "version" => 0,
        ]);
        $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
        return [$f, $student, $section, $enrollment, $credit, $grades];
    }

    private function review(array $fixture, string $grade = "D", bool $evidence = false): array
    {
        [$f, $student, $section, $enrollment, $credit, $grades] = $fixture;
        $row = $f->sql->one("sis_academic_credits", ["id" => $credit->value]);
        return $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades[$grade]),
                $row["version"],
                reason: "Fictional institutional records support this reconciliation.",
                gradeEvidence: $evidence,
                reconcileQuantities: true,
            );
    }

    private function snapshot(SisFixture $f): array
    {
        $tables = [
            "sis_history_events",
            "sis_history_operations",
            "sis_history_streams",
            "sis_academic_credits",
            "sis_academic_credit_streams",
            "sis_grade_change_requests",
            "sis_audit",
            "sis_enrollments",
        ];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = $f->sql->rows($table);
        }
        return $rows;
    }

    public function testExplicitReviewPreservesOriginalFactsAndReconcilesOnlyOnConfirmation(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $grades] = $fixture;
        $before = $this->snapshot($f);
        $ledger = $f->academicCredits();
        $choices = $ledger->choices($student, $section, $enrollment);
        self::assertTrue($choices["requires_quantity_reconciliation"]);
        self::assertFalse($choices["can_accept_course"]);
        $review = $this->review($fixture);
        self::assertTrue($review["withdrawal_quantity_reconciliation"]);
        self::assertSame(1000, $review["withdrawal_quantities"]["completed_milli"]);
        self::assertSame(3000, $review["withdrawal_quantities"]["expected_grade_points_milli"]);
        self::assertSame($before, $this->snapshot($f));
        $money = $f->financialEvents();
        $receipt = $ledger->commit($student, $section, $review["review_token"]);
        $facts = $ledger->auditFacts($credit, $student);
        self::assertSame("W", $facts["credit"]["status"]);
        self::assertSame(3000, $facts["credit"]["attempted_milli"]);
        self::assertSame(0, $facts["credit"]["completed_milli"]);
        self::assertSame(3000, $facts["credit"]["grade_points_milli"]);
        self::assertSame($grades["D"], $facts["credit"]["grade_id"]);
        self::assertTrue($facts["earlier_history_unknown"]);
        self::assertSame($money, $f->financialEvents());
        self::assertSame($before["sis_enrollments"], $f->sql->rows("sis_enrollments"));
        self::assertSame($receipt, $ledger->commit($student, $section, $review["review_token"]));
        $history = $ledger->history($student, $section, $enrollment);
        self::assertTrue(end($history["items"])["withdrawal_quantity_reconciled"]);
        self::assertStringNotContainsString(
            "Fictional institutional records",
            json_encode($history, JSON_THROW_ON_ERROR),
        );
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\AcademicRecord\ValueObject\AcademicCreditId($credit->value),
            "academic_credit",
        );
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $events = $history->page($stream);
        self::assertSame(1000, $events->events[0]->event->payload()["credit"]["completed_milli"]);
        self::assertSame(9999, $events->events[0]->event->payload()["credit"]["grade_points_milli"]);
    }

    public function testUnconfirmedOrUnnecessaryReconciliationWritesNothing(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, , $grades] = $fixture;
        $before = $this->snapshot($f);
        try {
            $f->academicCredits()->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades["D"]),
                0,
                reason: "Fictional ordinary correction.",
            );
            self::fail("An ordinary grade review silently reconciled credit quantities.");
        } catch (ValidationException $error) {
            self::assertSame("reconcile_withdrawal_quantities", $error->errors[0]["field"]);
            self::assertSame($before, $this->snapshot($f));
        }
        $review = $this->review($fixture);
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $before = $this->snapshot($f);
        try {
            $this->review($fixture);
            self::fail("Unnecessary reconciliation was accepted.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testMissingGradeNeedsBothEvidenceConfirmationsAndNeverAwardsPassingCredit(): void
    {
        $fixture = $this->fixture(missingGrade: true);
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $before = $this->snapshot($f);
        try {
            $this->review($fixture, "A");
            self::fail("Missing grade evidence was skipped.");
        } catch (ValidationException $error) {
            self::assertSame("grade_evidence", $error->errors[0]["field"]);
            self::assertSame($before, $this->snapshot($f));
        }
        $review = $this->review($fixture, "A", true);
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame(0, $facts["credit"]["completed_milli"]);
        self::assertSame(12000, $facts["credit"]["grade_points_milli"]);
        self::assertNull($facts["completed_on"]);
        self::assertTrue(
            $f->academicCredits()->choices($student, $section, $enrollment)["can_accept_course"],
        );
    }

    public function testGpaOnlyMismatchCanBeReconciledUsingANonGpaGrade(): void
    {
        $fixture = $this->fixture(gradeCode: "W", earned: 0, points: 5000);
        [$f, $student, $section, , $credit] = $fixture;
        $review = $this->review($fixture, "W");
        self::assertSame(0, $review["withdrawal_quantities"]["expected_grade_points_milli"]);
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        self::assertSame(
            0,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["grade_points_milli"],
        );
    }

    public function testIndependentApprovalRetainsQuantitiesUntilAnotherAuthorizedRegistrarApproves(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, , $credit] = $fixture;
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $before = $f->sql->rows("sis_academic_credits");
        $review = $this->review($fixture);
        $receipt = $ledger->commit($student, $section, $review["review_token"]);
        $id = new EntityId($receipt["operation_id"]);
        self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        self::assertTrue($ledger->gradeChangeRequest($id)["withdrawal_quantity_reconciliation"]);
        try {
            $ledger->reviewGradeChange($id, "approved", "Fictional self approval.");
            self::fail("The requester approved their reconciliation.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        }
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
        self::assertFalse($ledger->gradeChangeRequest($id)["can_approve"]);
        $f->access->grants[] = "sis:gradebook:closed";
        $detail = $ledger->gradeChangeRequest($id);
        self::assertSame(1000, $detail["withdrawal_quantities"]["completed_milli"]);
        $decision = $ledger->reviewGradeChange(
            $id,
            "approved",
            "Fictional independent institutional evidence.",
        );
        $accepted = $ledger->resolveGradeChange($id, $decision["review_token"]);
        self::assertSame(0, $ledger->auditFacts($credit, $student)["credit"]["completed_milli"]);
        self::assertSame($accepted, $ledger->resolveGradeChange($id, $decision["review_token"]));
        self::assertSame(1000, $ledger->gradeChangeRequest($id)["withdrawal_quantities"]["completed_milli"]);
    }

    public function testRejectingOrCancellingKeepsTheInconsistentFactsAndMarksNoCompletedReconciliation(): void
    {
        foreach (["rejected", "cancelled"] as $outcome) {
            $fixture = $this->fixture();
            [$f, $student, $section, $enrollment] = $fixture;
            $ledger = $f->academicCredits();
            $ledger->saveGradeChangePolicy("approval", 0);
            $before = $f->sql->rows("sis_academic_credits");
            $receipt = $ledger->commit($student, $section, $this->review($fixture)["review_token"]);
            $id = new EntityId($receipt["operation_id"]);
            if ($outcome === "rejected") {
                $f->access->actor = $f->user();
            }
            $decision = $ledger->reviewGradeChange($id, $outcome, "Fictional unresolved evidence.");
            $ledger->resolveGradeChange($id, $decision["review_token"]);
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
            self::assertTrue(
                $ledger->choices($student, $section, $enrollment)["requires_quantity_reconciliation"],
            );
            $history = $ledger->history($student, $section, $enrollment);
            self::assertFalse(end($history["items"])["withdrawal_quantity_reconciled"]);
        }
    }

    public function testExpiredStaleForeignAndUnauthorizedConfirmationsPreserveAllFacts(): void
    {
        foreach (["expiry", "actor", "permission", "stale"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $review = $this->review($fixture);
            if ($failure === "expiry") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            } elseif ($failure === "actor") {
                $f->access->actor = $f->user();
            } elseif ($failure === "permission") {
                $f->access->staff = false;
                $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
            } else {
                $other = $this->review($fixture, "A");
                $f->academicCredits()->commit($student, $section, $other["review_token"]);
            }
            $before = $this->snapshot($f);
            try {
                $f->academicCredits()->commit($student, $section, $review["review_token"]);
                self::fail("An unsafe reconciliation was accepted.");
            } catch (ConcurrentModificationException | AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testAuditFailureAndLatePermissionLossRollBackObservationReconciliationAndReceipt(): void
    {
        foreach (["audit", "permission", "expiry"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $review = $this->review($fixture);
            $audit = new class ($f, $failure) implements AuditTrail {
                public function __construct(private SisFixture $f, private string $failure)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->failure === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    if ($this->failure === "expiry") {
                        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
                    } else {
                        $this->f->access->staff = false;
                        $this->f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $before = $this->snapshot($f);
            try {
                $f->academicCredits($audit)->commit($student, $section, $review["review_token"]);
                self::fail("A failed required check left reconciled facts behind.");
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testTrackedProjectionDamageIsNotReconciledAsAnOlderObservation(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $review = $this->review($fixture);
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $f->sql->update("sis_academic_credits", ["completed_milli" => 1], ["id" => $credit->value]);
        $before = $this->snapshot($f);
        try {
            $f->academicCredits()->choices($student, $section, $enrollment);
            self::fail("Tracked projection damage became a reconciliation opportunity.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testAcceptedRetryDoesNotUndoALaterGradeAndStillRequiresCurrentClosedAuthority(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $grades] = $fixture;
        $ledger = $f->academicCredits();
        $review = $this->review($fixture);
        $receipt = $ledger->commit($student, $section, $review["review_token"]);
        $later = $ledger->review(
            $student,
            $section,
            $enrollment,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional later grade evidence.",
        );
        $ledger->commit($student, $section, $later["review_token"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $this->snapshot($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        try {
            $ledger->commit($student, $section, $review["review_token"]);
            self::fail("An accepted receipt bypassed current closed-record authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->access->grants[] = "sis:gradebook:closed";
        self::assertSame($receipt, $ledger->commit($student, $section, $review["review_token"]));
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(12000, $ledger->auditFacts($credit, $student)["credit"]["grade_points_milli"]);
    }

    public function testEarlierUnacceptedProofWithoutExplicitReconciliationMustBeReviewedAgain(): void
    {
        $fixture = $this->fixture(earned: 0);
        [$f, $student, $section] = $fixture;
        $review = $this->review($fixture);
        $data = json_decode($f->dropCipher->open($review["review_token"]), true, 16, JSON_THROW_ON_ERROR);
        unset($data["withdrawal_quantity_reconciliation"], $data["withdrawal_quantities"]);
        $before = $this->snapshot($f);
        try {
            $f->academicCredits()->commit(
                $student,
                $section,
                $f->dropCipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            );
            self::fail("An earlier unaccepted review bypassed explicit quantity confirmation.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testReconciliationMetadataRequiresAnExplicitWithdrawnScopeAndBooleanTrue(): void
    {
        [$f, , , , $credit, $grades] = $this->fixture();
        $grade = \Domain\AcademicRecord\ValueObject\GradeSnapshot::fromRow(
            $f->gradeCatalog()->snapshot(new EntityId($grades["D"])),
        )->facts;
        $registry = new \Infrastructure\EventSourcing\HistoricalSchemaRegistry();
        foreach ([[false, true], [1, true], ["true", true], [true, false]] as [$value, $withdrawn]) {
            $metadata = [
                "__aggregate_type" => "academic_credit",
                "withdrawal_quantity_reconciliation" => $value,
            ];
            if ($withdrawn) {
                $metadata["withdrawal_grade_correction"] = true;
            }
            $event = \Domain\AcademicRecord\Event\FinalGradeWasDecided::occur(
                new \Domain\AcademicRecord\ValueObject\AcademicCreditId($credit->value),
                [
                    "decision_id" => EntityId::generate()->value,
                    "previous_decision_id" => EntityId::generate()->value,
                    "grade" => $grade,
                    "assignment_id" => null,
                ],
                $metadata,
            )->withPlayhead(1);
            try {
                $registry->describe($event);
                self::fail("Invalid reconciliation metadata was accepted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReconciliationCannotBeUsedOnAnActiveInitialGrade(): void
    {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $f->student()->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        $enrollment = $f->service->register($student, $section)->id;
        $ledger = $f->academicCredits();
        $grade = new EntityId($ledger->choices($student, $section, $enrollment)["items"][0]["id"]);
        $before = $this->snapshot($f);
        try {
            $ledger->review($student, $section, $enrollment, $grade, 0, reconcileQuantities: true);
            self::fail("A reconciliation flag changed an active attempt.");
        } catch (ValidationException $error) {
            self::assertSame("reconcile_withdrawal_quantities", $error->errors[0]["field"]);
            self::assertSame($before, $this->snapshot($f));
        }
    }
}
