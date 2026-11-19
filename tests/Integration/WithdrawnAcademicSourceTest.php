<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\AcademicRecord\ValueObject\AcademicDateKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class WithdrawnAcademicSourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(
        bool $earlier = false,
        bool $missingGrade = false,
        int $earlierPoints = 0,
        bool $withdrawn = true,
    ): array {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "D",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 1000,
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
        $student = $f->student($f->user())->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        $course = $f->registration->section($section)->courseId;
        $publication = $f->publishCourse($course);
        if ($earlier) {
            $enrollment = EntityId::generate();
            $credit = EntityId::generate();
            $grade = array_column($f->sql->rows("sis_grades"), "id", "code")["W"];
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
                "course_title" => "Fictional older title",
                "attempted_milli" => 3000,
                "completed_milli" => 0,
                "grade_points_milli" => $earlierPoints,
                "grade_id" => $missingGrade ? null : $grade,
                "status" => "W",
                "version" => 0,
            ]);
            $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
            return [$f, $student, $section, $enrollment, $credit, $publication];
        }
        $enrollment = $f->service->register($student, $section, true)->id;
        $credit = new EntityId(
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        if (!$withdrawn) {
            return [$f, $student, $section, $enrollment, $credit, $publication];
        }
        $f->publishDropPolicy($f->term, -1, new DropWindows([["through" => null, "grade_code" => "W"]]));
        $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
        $f->service->drop($student, $section);
        return [$f, $student, $section, $enrollment, $credit, $publication];
    }

    private function snapshot(SisFixture $f): array
    {
        $out = [];
        $cases = [
                "sis_history_events",
                "sis_history_operations",
                "sis_history_streams",
                "sis_academic_credits",
                "sis_credit_course_versions",
                "sis_audit",
                "sis_enrollments",
                "sis_work_pursuit_scopes",
        ];
        foreach ($cases as $table) {
            $out[$table] = $f->sql->rows($table);
        }
        return $out;
    }

    private function preserved(SisFixture $f): array
    {
        return [
            $f->financialEvents(),
            $f->sql->rows("sis_enrollments"),
            $f->sql->rows("sis_work_pursuit_scopes"),
        ];
    }

    private function period(array $fixture): array
    {
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $version = $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"];
        return $f
            ->academicDates()
            ->reviewChronology(
                $student,
                $section,
                $enrollment,
                "2026-08-01",
                null,
                $version,
                "Fictional institutional source-period evidence.",
            );
    }

    private function course(array $fixture): array
    {
        [$f, $student, $section, $enrollment, $credit, $publication] = $fixture;
        $version = $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"];
        return $f
            ->academicCourses()
            ->review(
                $student,
                $section,
                $enrollment,
                $publication,
                $version,
                "2026-09-02",
                "Fictional institutional course-publication evidence.",
            );
    }

    public function testBothSourceReviewsPreserveWithdrawalAwardsFinancePursuitAndOriginalEvents(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $publication] = $fixture;
        $before = $this->preserved($f);
        $events = $f->sql->rows("sis_history_events");
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        self::assertTrue($choices["can_accept_course"]);
        self::assertTrue($choices["can_record_academic_period"]);
        $review = $this->course($fixture);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $courseReceipt = $f->academicCourses()->commit($student, $section, $review["review_token"]);
        $dates = $this->period($fixture);
        $dateReceipt = $f->academicDates()->commitChronology($student, $section, $dates["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame("2026-08-01", $facts["academic_on"]);
        self::assertNull($facts["completed_on"]);
        self::assertSame("W", $facts["credit"]["status"]);
        self::assertSame(0, $facts["credit"]["completed_milli"]);
        self::assertSame(0, $facts["credit"]["grade_points_milli"]);
        self::assertSame($publication->value, $facts["credit"]["course_version_id"]);
        self::assertSame($before, $this->preserved($f));
        self::assertSame($events, array_slice($f->sql->rows("sis_history_events"), 0, count($events)));
        $verified = $f->inputVerifier()->verify($student, new HistoryReadBudget());
        self::assertSame("W", $verified->items[0]["status"]);
        self::assertSame(0, $verified->items[0]["completed_milli"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $saved = $this->snapshot($f);
        self::assertSame(
            $courseReceipt,
            $f->academicCourses()->commit($student, $section, $review["review_token"]),
        );
        self::assertSame(
            $dateReceipt,
            $f->academicDates()->commitChronology($student, $section, $dates["review_token"]),
        );
        self::assertSame($saved, $this->snapshot($f));
        $history = json_encode(
            $f->academicCredits()->history($student, $section, $enrollment),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringContainsString("course_accepted", $history);
        self::assertStringContainsString("period_recorded", $history);
        self::assertStringNotContainsString("Fictional institutional", $history);
    }

    public function testClosedRecordDutyIsNeededForChoicesReviewAndEveryConfirmationIncludingRetries(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment] = $fixture;
        $course = $this->course($fixture);
        $period = $this->period($fixture);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $before = $this->snapshot($f);
        $cases = [
                fn() => $f->academicCredits()->choices($student, $section, $enrollment),
                fn() => $f->academicCourses()->choices($student, $section, $enrollment),
                fn() => $this->course($fixture),
                fn() => $this->period($fixture),
                fn() => $f->academicCourses()->commit($student, $section, $course["review_token"]),
                fn() => $f->academicDates()->commitChronology($student, $section, $period["review_token"]),
        ];
        foreach ($cases as $action) {
            try {
                $action();
                self::fail("Ordinary amendment duties changed a withdrawn academic source.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $f->access->grants[] = "sis:gradebook:closed";
        $f->academicCourses()->commit($student, $section, $course["review_token"]);
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $before = $this->snapshot($f);
        try {
            $f->academicCourses()->commit($student, $section, $course["review_token"]);
            self::fail("A retry bypassed current closed-record authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testWithdrawnAttemptCannotBeMarkedCompletedThroughEitherDateEntryPoint(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $version = $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"];
        $before = $this->snapshot($f);
        $cases = [
                fn() => $f
                    ->academicDates()
                    ->review($student, $section, $enrollment, "2026-08-30", $version, "Fictional reason."),
                fn() => $f
                    ->academicDates()
                    ->reviewChronology(
                        $student,
                        $section,
                        $enrollment,
                        "2026-08-01",
                        "2026-08-30",
                        $version,
                        "Fictional reason.",
                    ),
        ];
        foreach ($cases as $action) {
            try {
                $action();
                self::fail("A withdrawn attempt was marked completed.");
            } catch (ValidationException $e) {
                self::assertSame("completed_on", $e->errors[0]["field"]);
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testCourseAndDateReviewsShareStaleProtectionAndCanClearOnlyTheSourcePeriod(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $period = $this->period($fixture);
        $course = $this->course($fixture);
        $f->academicCourses()->commit($student, $section, $course["review_token"]);
        $before = $this->snapshot($f);
        try {
            $f->academicDates()->commitChronology($student, $section, $period["review_token"]);
            self::fail("A stale source-period review changed the record.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $period = $this->period($fixture);
        $receipt = $f->academicDates()->commitChronology($student, $section, $period["review_token"]);
        $version = $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"];
        $clear = $f
            ->academicDates()
            ->review(
                $student,
                $section,
                $enrollment,
                null,
                $version,
                "Fictional evidence withdrawn.",
                AcademicDateKind::Period,
            );
        $f->academicDates()->commit($student, $section, $clear["review_token"], AcademicDateKind::Period);
        self::assertSame(
            $receipt,
            $f->academicDates()->commitChronology($student, $section, $period["review_token"]),
        );
        self::assertNull($f->academicCredits()->auditFacts($credit, $student)["academic_on"]);
    }

    public function testPassingWithdrawalGradeKeepsItsOriginalGpaWithoutAwardingCredit(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        $grades = array_column($choices["items"], "id", "code");
        $grade = $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades["D"]),
                $choices["current_version"],
                reason: "Fictional late-drop policy correction.",
            );
        $f->academicCredits()->commit($student, $section, $grade["review_token"]);
        $before = $this->preserved($f);
        $course = $this->course($fixture);
        $f->academicCourses()->commit($student, $section, $course["review_token"]);
        $period = $this->period($fixture);
        $f->academicDates()->commitChronology($student, $section, $period["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student)["credit"];
        self::assertSame(3000, $facts["grade_points_milli"]);
        self::assertSame(0, $facts["completed_milli"]);
        self::assertSame($grades["D"], $facts["grade_id"]);
        self::assertSame($before, $this->preserved($f));
    }

    public function testPendingGradeCorrectionMustBeResolvedBeforeSourceCorrections(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment] = $fixture;
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $choices = $ledger->choices($student, $section, $enrollment);
        $grades = array_column($choices["items"], "id", "code");
        $review = $ledger->review(
            $student,
            $section,
            $enrollment,
            new EntityId($grades["D"]),
            $choices["current_version"],
            reason: "Fictional pending grade correction.",
        );
        $ledger->commit($student, $section, $review["review_token"]);
        $before = $this->snapshot($f);
        foreach ([fn() => $this->course($fixture), fn() => $this->period($fixture)] as $action) {
            try {
                $action();
                self::fail("An unresolved grade request was bypassed.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $choices = $ledger->choices($student, $section, $enrollment);
        self::assertFalse($choices["can_accept_course"]);
        self::assertFalse($choices["can_record_academic_period"]);
    }

    public function testAuditFailureAndLateAuthorityLossRollBackEitherSourceDecision(): void
    {
        foreach (["course", "period"] as $kind) {
            foreach (["audit", "authority", "expiry"] as $failure) {
                $fixture = $this->fixture();
                [$f, $student, $section] = $fixture;
                $review = $kind === "course" ? $this->course($fixture) : $this->period($fixture);
                $before = $this->snapshot($f);
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
                            return;
                        }
                        $this->f->access->staff = false;
                        $this->f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
                    }
                    public function forSubject(EntityId $subject): array
                    {
                        return [];
                    }
                };
                try {
                    if ($kind === "course") {
                        $f->academicCourses($audit)->commit($student, $section, $review["review_token"]);
                    } else {
                        $f->academicDates($audit)->commitChronology(
                            $student,
                            $section,
                            $review["review_token"],
                        );
                    }
                    self::fail("A source correction survived audit/authority failure.");
                } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                    self::assertSame($before, $this->snapshot($f));
                }
            }
        }
    }

    public function testExpiredSourceReviewsCannotWriteAndDoNotChangeAnotherStudent(): void
    {
        foreach (["course", "period"] as $kind) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $review = $kind === "course" ? $this->course($fixture) : $this->period($fixture);
            $f->clock->time = $f->clock->time->modify("+21 minutes");
            $before = $this->snapshot($f);
            try {
                if ($kind === "course") {
                    $f->academicCourses()->commit($student, $section, $review["review_token"]);
                } else {
                    $f->academicDates()->commitChronology($student, $section, $review["review_token"]);
                }
                self::fail("An expired source review changed a withdrawn record.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($f));
            }
            $other = $f->student()->id;
            $before = $this->snapshot($f);
            try {
                if ($kind === "course") {
                    $f->academicCourses()->commit($other, $section, $review["review_token"]);
                } else {
                    $f->academicDates()->commitChronology($other, $section, $review["review_token"]);
                }
                self::fail("A review changed a different student's record.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testEarlierWithdrawnFactsNeedGradeHistoryBeforeEitherSourceReview(): void
    {
        $fixture = $this->fixture(earlier: true, missingGrade: true);
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $before = $this->snapshot($f);
        foreach ([fn() => $this->course($fixture), fn() => $this->period($fixture)] as $action) {
            try {
                $action();
                self::fail("Unverified older withdrawal facts were silently accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        self::assertFalse($choices["can_accept_course"]);
        self::assertFalse($choices["can_record_academic_period"]);
        $grades = array_column($choices["items"], "id", "code");
        $grade = $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades["W"]),
                0,
                reason: "Fictional original grade evidence.",
                gradeEvidence: true,
            );
        $f->academicCredits()->commit($student, $section, $grade["review_token"]);
        $course = $this->course($fixture);
        $f->academicCourses()->commit($student, $section, $course["review_token"]);
        $period = $this->period($fixture);
        $f->academicDates()->commitChronology($student, $section, $period["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertTrue($facts["earlier_history_unknown"]);
        self::assertSame("2026-08-01", $facts["academic_on"]);
        self::assertSame(0, $facts["credit"]["completed_milli"]);
    }

    public function testInconsistentObservedWithdrawalGpaDoesNotBecomeTrustedThroughSourceCorrection(): void
    {
        $fixture = $this->fixture(earlier: true, earlierPoints: 3000);
        [$f, $student, $section, $enrollment] = $fixture;
        $before = $this->snapshot($f);
        foreach ([fn() => $this->course($fixture), fn() => $this->period($fixture)] as $action) {
            try {
                $action();
                self::fail("Inconsistent older withdrawal quantities were accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        self::assertFalse($choices["can_accept_course"]);
        self::assertFalse($choices["can_record_academic_period"]);
    }

    public function testNewGradeRequestInvalidatesPreparedSourceDecisionsWithoutChangingWithdrawnFacts(): void
    {
        foreach (["course", "period"] as $kind) {
            $fixture = $this->fixture();
            [$f, $student, $section, $enrollment] = $fixture;
            $review = $kind === "course" ? $this->course($fixture) : $this->period($fixture);
            $ledger = $f->academicCredits();
            $ledger->saveGradeChangePolicy("approval", 0);
            $choices = $ledger->choices($student, $section, $enrollment);
            $grades = array_column($choices["items"], "id", "code");
            $grade = $ledger->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades["D"]),
                $choices["current_version"],
                reason: "Fictional new grade correction.",
            );
            $ledger->commit($student, $section, $grade["review_token"]);
            $before = $this->snapshot($f);
            try {
                if ($kind === "course") {
                    $f->academicCourses()->commit($student, $section, $review["review_token"]);
                } else {
                    $f->academicDates()->commitChronology($student, $section, $review["review_token"]);
                }
                self::fail("A prepared source decision bypassed a new pending grade request.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testOriginallyActiveAcceptanceRetryRequiresClosedDutyAfterLaterWithdrawal(): void
    {
        [$f, $student, $section, $enrollment, , $version] = $this->fixture(withdrawn: false);
        $service = $f->academicCourses();
        $review = $service->review(
            $student,
            $section,
            $enrollment,
            $version,
            0,
            "2026-08-10",
            "Fictional active acceptance.",
        );
        $receipt = $service->commit($student, $section, $review["review_token"]);
        $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
        $f->drops->drop($student, $section);
        self::assertSame("W", $f->sql->one("sis_enrollments", ["id" => $enrollment->value])["status"]);
        $before = $this->snapshot($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        try {
            $service->commit($student, $section, $review["review_token"]);
            self::fail("A formerly active receipt bypassed current closed-record authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->access->grants[] = "sis:gradebook:closed";
        self::assertSame($receipt, $service->commit($student, $section, $review["review_token"]));
        self::assertSame($before, $this->snapshot($f));
    }
}
