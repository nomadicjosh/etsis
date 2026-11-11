<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AcademicCompletionTest extends TestCase
{
    private function fixture(bool $post = true): array
    {
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
                "min_score_milli" => 0,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $f->student()->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        $enrollment = $f->service->register($student, $section)->id;
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        if ($post) {
            $review = $f
                ->academicCredits()
                ->review($student, $section, $enrollment, new EntityId($choices["items"][0]["id"]), 0);
            $f->academicCredits()->commit($student, $section, $review["review_token"]);
        }
        $credit = new EntityId(
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        return [$f, $student, $section, $enrollment, $credit, $choices];
    }

    public function testDateIsReviewedAndRecordedWithoutChangingAwardsFinanceOrEarlierEvents(): void
    {
        [$f, $student, $section, $enrollment, $credit] = $this->fixture();
        $facts = $f->sql->one("sis_academic_credits", ["id" => $credit->value]);
        $events = $f->sql->rows("sis_history_events");
        $finance = $f->financialEvents();
        $service = $f->academicCompletions();
        $review = $service->review(
            $student,
            $section,
            $enrollment,
            "2026-07-15",
            1,
            "Fictional verified prior completion.",
        );
        self::assertNull($review["previous_completed_on"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $receipt = $service->commit($student, $section, $review["review_token"]);
        self::assertSame(3, $receipt["last_version"]);
        self::assertSame("2026-07-15", $f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
        self::assertSame(
            "2026-07-15",
            $f->academicCredits()->evaluationFacts($credit, $student, new HistoryReadBudget())[
                "completed_on"
            ],
        );
        $after = $f->sql->one("sis_academic_credits", ["id" => $credit->value]);
        self::assertEquals(array_replace($facts, ["version" => 2]), $after);
        self::assertSame($finance, $f->financialEvents());
        foreach ($events as $event) {
            self::assertSame($event, $f->sql->one("sis_history_events", ["id" => $event["id"]]));
        }
        $history = $f->academicCredits()->history($student, $section, $enrollment);
        self::assertSame("completion_recorded", $history["items"][2]["action"]);
        self::assertSame("2026-07-15", $history["items"][2]["completed_on"]);
        self::assertStringNotContainsString("Fictional verified", json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testOriginalRetryDoesNotUndoACorrectionOrClearEvenAfterExpiry(): void
    {
        [$f, $student, $section, $enrollment, $credit] = $this->fixture();
        $s = $f->academicCompletions();
        $first = $s->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional first date.");
        $receipt = $s->commit($student, $section, $first["review_token"]);
        $next = $s->review($student, $section, $enrollment, "2026-08-02", 2, "Fictional corrected source.");
        $s->commit($student, $section, $next["review_token"]);
        $clear = $s->review($student, $section, $enrollment, null, 3, "Fictional source date withdrawn.");
        $s->commit($student, $section, $clear["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($receipt, $s->commit($student, $section, $first["review_token"]));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertNull($f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
    }

    public function testGradeCorrectionKeepsActualCompletionDateAndStalesAnEarlierDateReview(): void
    {
        [$f, $student, $section, $enrollment, $credit, $choices] = $this->fixture();
        $s = $f->academicCompletions();
        $initial = $s->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional completion.");
        $s->commit($student, $section, $initial["review_token"]);
        $pending = $s->review(
            $student,
            $section,
            $enrollment,
            "2026-08-02",
            2,
            "Fictional competing correction.",
        );
        $grade = $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                new EntityId($choices["items"][1]["id"]),
                2,
                reason: "Fictional grade correction.",
            );
        $f->academicCredits()->commit($student, $section, $grade["review_token"]);
        self::assertSame("2026-08-01", $f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
        $events = $f->sql->rows("sis_history_events");
        try {
            $s->commit($student, $section, $pending["review_token"]);
            self::fail("A stale date review changed a later grade decision.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }

    public function testInvalidDatesReasonsAndUnchangedDatesWriteNothing(): void
    {
        [$f, $student, $section, $enrollment] = $this->fixture();
        $events = $f->sql->rows("sis_history_events");
        foreach (
            [
                ["2026-02-30", "Reason"],
                ["2026-08-11", "Reason"],
                ["2026-8-1", "Reason"],
                ["2026-08-01", ""],
                [null, "Reason"],
                ["2026-08-01\0", "Reason"],
            ]
            as [$date, $reason]
        ) {
            try {
                $f->academicCompletions()->review($student, $section, $enrollment, $date, 1, $reason);
                self::fail("Invalid completion input accepted.");
            } catch (ValidationException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testUnconfirmedExpiredReviewRollsBackItsEventReceiptAndProjection(): void
    {
        [$f, $student, $section, $enrollment, $credit] = $this->fixture();
        $s = $f->academicCompletions();
        $r = $s->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional expired review.");
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        try {
            $s->commit($student, $section, $r["review_token"]);
            self::fail("Expired review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
            self::assertNull($f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
        }
    }

    public function testPendingAttemptCannotBeMarkedCompletedByEnteringADate(): void
    {
        [$f, $student, $section, $enrollment] = $this->fixture(false);
        $this->expectException(ValidationException::class);
        $f->academicCompletions()->review(
            $student,
            $section,
            $enrollment,
            "2026-08-01",
            0,
            "Fictional unsupported completion.",
        );
    }

    public function testPermissionScopeAndActorAreRequiredAgainAtConfirmation(): void
    {
        [$f, $student, $section, $enrollment] = $this->fixture();
        $s = $f->academicCompletions();
        $r = $s->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional scoped date.");
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:manage", "sis:student:read"];
        try {
            $s->commit($student, $section, $r["review_token"]);
            self::fail("Ordinary faculty changed a completion date.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $s->commit($student, $section, $r["review_token"]);
    }
    public function testAuditFailureOrRevokedAuthorityRollsBackTheWholeCompletionDecision(): void
    {
        foreach (["audit", "revoked"] as $failure) {
            [$f, $student, $section, $enrollment, $credit] = $this->fixture();
            $audit = new class ($f, $failure) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(private SisFixture $f, private string $failure) {}
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->failure === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    $this->f->access->staff = false;
                    $this->f->access->grants = [];
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $s = $f->academicCompletions($audit);
            $r = $s->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional atomic correction.");
            $events = $f->sql->rows("sis_history_events");
            $receipts = $f->sql->rows("sis_history_operations");
            try {
                $s->commit($student, $section, $r["review_token"]);
                self::fail("A failed atomic decision committed.");
            } catch (AccessDeniedException | RuntimeException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
                self::assertNull($f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
            }
        }
    }
    public function testReplayRejectsACompletionEventWhosePriorDateDisagreesWithTheRetainedFact(): void
    {
        [$f, $student, $section, $enrollment] = $this->fixture();
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->transactions->run(function () use ($f, $student, $section, $enrollment): void {
            $repository = new \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository($f->sql);
            $repository->lockStudent($student);
            $repository->lockSection($section);
            [$id, $root] = $f->academicCredits()->decisionRecord($student, $section, $enrollment);
            $event = \Domain\AcademicRecord\Event\AcademicCompletionWasRecorded::occur(
                new \Domain\AcademicRecord\ValueObject\AcademicCreditId($id->value),
                [
                    "decision_id" => EntityId::generate()->value,
                    "previous_decision_id" => $root->decision(),
                    "previous_completed_on" => "2026-07-01",
                    "completed_on" => "2026-08-01",
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
        });
    }
    public function testChangedEnrollmentScopeCannotAuthorizeADateAgainstAnotherStudentsOrSectionsRow(): void
    {
        foreach (["student_id", "section_id"] as $field) {
            [$f, $student, $section, $enrollment] = $this->fixture();
            $replacement = $field === "student_id" ? $f->student()->id : $f->section()->id;
            $f->sql->update("sis_enrollments", [$field => $replacement->value], ["id" => $enrollment->value]);
            $events = $f->sql->rows("sis_history_events");
            try {
                $f->academicCompletions()->review(
                    $student,
                    $section,
                    $enrollment,
                    "2026-08-01",
                    1,
                    "Fictional corrupted scope.",
                );
                self::fail("Changed enrollment scope was accepted.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
            }
        }
    }
}
