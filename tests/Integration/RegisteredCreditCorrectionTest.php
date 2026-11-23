<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Service\RegisteredCreditService;
use Domain\AcademicRecord\ValueObject\RegisteredCreditCorrection;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegisteredCreditCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $enrollment = $s->service->register($student->id, $section->id);
        $pursuit = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0][
                "id"
            ],
        );
        return [$s, $student->id, $section->id, $enrollment->id, $pursuit];
    }
    private function service(
        SisFixture $s,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): RegisteredCreditService {
        return new RegisteredCreditService(
            $s->academicCredits(),
            $s->enrollmentLedger(),
            new SqlAcademicCreditRepository($s->sql),
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
        );
    }
    private function review(array $f, int $milli = 4500): array
    {
        [$s, $student, $section, $enrollment] = $f;
        $service = $this->service($s);
        $v = $service->view($student, $section, $enrollment);
        return $service->review(
            $student,
            $section,
            $enrollment,
            $v["expected_version"],
            $v["expected_position"],
            $milli,
            "Fictional documented registration correction.",
            true,
        );
    }
    private function confirm(array $f, array $r, ?RegisteredCreditService $service = null): array
    {
        [$s, $student, $section, $enrollment] = $f;
        return ($service ?? $this->service($s))->confirm(
            $student,
            $section,
            $enrollment,
            new EntityId($r["operation_id"]),
            $r["expected_version"],
            $r["expected_position"],
            $r["previous_registered_milli"],
            $r["registered_milli"],
            $r["reason"],
            $r["acknowledged"],
            $r["review_token"],
        );
    }
    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_audit",
            "sis_enrollments",
            "sis_student_accounts",
        ]);
    }
    public function testRegisteredLoadChangesButOriginalAwardAndChargesRemainSeparate(): void
    {
        $f = [$s, $student, $section, $enrollment, $pursuit] = $this->fixture();
        $before = $this->service($s)->view($student, $section, $enrollment);
        $financial = $s->sql->rows("sis_student_accounts");
        $enrollments = $s->sql->rows("sis_enrollments");
        $this->confirm($f, $this->review($f));
        $after = $this->service($s)->view($student, $section, $enrollment);
        self::assertSame(4500, $after["current_registered_milli"]);
        self::assertSame($before["original_registered_milli"], $after["original_registered_milli"]);
        self::assertSame($before["awarded_attempted_milli"], $after["awarded_attempted_milli"]);
        self::assertSame($financial, $s->sql->rows("sis_student_accounts"));
        self::assertSame($enrollments, $s->sql->rows("sis_enrollments"));
        self::assertSame(
            4500,
            $s->enrollmentLoad()->review($student, $pursuit, $s->term, "staff")["credits_milli"],
        );
        $history = $this->service($s)->history($student, $section, $enrollment);
        self::assertSame(4500, $history["items"][0]["registered_milli"]);
        self::assertArrayNotHasKey("reason", $history["items"][0]);
        self::assertArrayNotHasKey("actor_id", $history["items"][0]);
    }
    public function testOriginalExpiredRetryDoesNotRestoreAnEarlierQuantityOrRepeatACharge(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $r = $this->review($f);
        $receipt = $this->confirm($f, $r);
        $this->confirm($f, $this->review($f, 0));
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        $before = $this->facts($s);
        self::assertSame($receipt, $this->confirm($f, $r));
        self::assertSame($before, $this->facts($s));
        self::assertSame(
            0,
            $this->service($s)->view($student, $section, $enrollment)["current_registered_milli"],
        );
    }
    public function testMissingIndependentAuthorityOrAcknowledgementCannotWrite(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $v = $this->service($s)->view($student, $section, $enrollment);
        $before = $this->facts($s);
        try {
            $this->service($s)->review(
                $student,
                $section,
                $enrollment,
                $v["expected_version"],
                $v["expected_position"],
                4500,
                "Fictional evidence.",
                false,
            );
            self::fail("Unacknowledged evidence was accepted.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read", "sis:registration:manage", "sis:gradebook:amend"];
        $this->expectException(AccessDeniedException::class);
        $this->review($f);
    }
    public function testStaleAndExpiredReviewsAndTamperedQuantitiesFailWithoutWriting(): void
    {
        $f = [$s] = $this->fixture();
        $r = $this->review($f);
        $changed = $r;
        $changed["registered_milli"] = 5500;
        try {
            $this->confirm($f, $changed);
            self::fail("A changed quantity reused a proof.");
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->confirm($f, $this->review($f, 2500));
        $before = $this->facts($s);
        try {
            $this->confirm($f, $r);
            self::fail("A stale review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $pending = $this->review($f, 4500);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $this->confirm($f, $pending);
            self::fail("An expired review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testFinalAuditAuthorityAndProjectionFailuresRollBackAllChanges(): void
    {
        foreach (["audit", "authority", "projection"] as $failure) {
            $f = [$s] = $this->fixture();
            $r = $this->review($f);
            $before = $this->facts($s);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($s, $failure): void {
                    if ($failure === "audit") {
                        throw new \RuntimeException("Fictional audit failure.");
                    }
                    if ($failure === "authority") {
                        $s->access->staff = false;
                        return;
                    }
                    $s->sql->execute(
                        "UPDATE sis_academic_credits SET attempted_milli = attempted_milli + 1000",
                    );
                });
            try {
                $this->confirm($f, $r, $this->service($s, $audit));
                self::fail("A final failure was accepted.");
            } catch (\RuntimeException | AccessDeniedException | CorruptHistoryException) {
                self::assertSame($before, $this->facts($s));
            }
        }
    }
    private function determinations(SisFixture $s): \Domain\Student\Service\LoadDeterminationService
    {
        return new \Domain\Student\Service\LoadDeterminationService(
            $s->enrollmentLoad(),
            new \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository($s->sql),
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $this->createStub(\Domain\Advising\Service\AdvisorProgressAccess::class),
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            new \Domain\Student\Service\LoadDeterminationLedger(
                new \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository($s->sql),
                \EventStoreFixture::history($s->connection->queryBuilder()),
            ),
        );
    }
    public function testRetainedLoadStaysOriginalPendingReviewsStaleAndNewDeterminationsUseTheCorrection(): void
    {
        $f = [$s, $student, $section, $enrollment, $pursuit] = $this->fixture();
        $policy = $s->loadPolicies();
        $p = $policy->review(
            null,
            [
                "bands" => [
                    ["code" => "PART", "label" => "Part time", "min_credits_milli" => 0],
                    ["code" => "FULL", "label" => "Full time", "min_credits_milli" => 4000],
                ],
            ],
            $policy->view()["revision"],
            "Fictional policy bands.",
        );
        $policy->publish(
            null,
            new EntityId($p["operation_id"]),
            $p["definition"],
            $p["revision"],
            $p["reason"],
            $p["review_token"],
        );
        $service = $this->determinations($s);
        $record = static fn(array $r): array => $service->confirm(
            $student,
            $pursuit,
            $s->term,
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["reason"],
            $r["review_token"],
        );
        $old = $service->review($student, $pursuit, $s->term, "Fictional initial determination.");
        $record($old);
        $original = $s->sql->rows("sis_load_determinations");
        $pending = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional unaccepted determination.",
        );
        $this->confirm($f, $this->review($f));
        $before = $this->facts($s);
        try {
            $record($pending);
            self::fail("A load review ignored the registered-credit correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        self::assertSame($original, $s->sql->rows("sis_load_determinations"));
        self::assertSame(
            3000,
            $service->listing($student, $pursuit, $s->term, "staff")["items"][0]["credits_milli"],
        );
        $fresh = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional current corrected determination.",
        );
        self::assertSame(4500, $fresh["result"]["credits_milli"]);
        self::assertSame("FULL", $fresh["result"]["classification"]["code"]);
        $record($fresh);
        self::assertCount(2, $s->sql->rows("sis_load_determinations"));
    }
    public function testPostedAwardAndGpaStayUnchangedAndFrameworkReplayRetainsBothQuantities(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $s->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $grade = $s->academicCredits()->choices($student, $section, $enrollment)["items"][0]["id"];
        $g = $s
            ->academicCredits()
            ->review($student, $section, $enrollment, new EntityId($grade), 0);
        $s->academicCredits()->commit($student, $section, $g["review_token"]);
        $before = $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value]);
        $this->confirm($f, $this->review($f, 0));
        $after = $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value]);
        unset($before["version"], $after["version"]);
        self::assertSame($before, $after);
        $root = \Domain\AcademicRecord\AcademicCredit::reconstituteFromEventStream(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\AcademicRecord\ValueObject\AcademicCreditId($before["id"]),
            ),
        );
        self::assertSame(0, $root->registeredCredits()["attempted_milli"]);
        self::assertSame(3000, $root->originalRegisteredCredits()["attempted_milli"]);
        self::assertSame(3000, $root->credit()["attempted_milli"]);
        self::assertSame(12000, $root->credit()["grade_points_milli"]);
    }
    public function testRetriesAfterEarlyDropDoNotResurrectAnyProjection(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $r = $this->review($f);
        $receipt = $this->confirm($f, $r);
        $s->service->drop($student, $section);
        self::assertSame([], $s->sql->rows("sis_academic_credits"));
        $before = $this->facts($s);
        self::assertSame($receipt, $this->confirm($f, $r));
        self::assertSame($before, $this->facts($s));
    }
    public function testOwnerLoadOmitsStaffRoutingAndDoesNotGrantCorrectionAuthority(): void
    {
        $f = [$s, $student, $section, $enrollment, $pursuit] = $this->fixture();
        $this->confirm($f, $this->review($f));
        $person = $s->sql->one("sis_students", ["id" => $student->value])["person_id"];
        $s->access->actor = $s->admissions->person(new EntityId($person))->ownerUserId;
        $s->access->staff = false;
        $s->access->grants = [];
        $view = $s->enrollmentLoad()->review($student, $pursuit, $s->term, "owner");
        self::assertSame(4500, $view["credits_milli"]);
        self::assertArrayNotHasKey("enrollment_id", $view["items"][0]);
        self::assertArrayNotHasKey("section_id", $view["items"][0]);
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->view($student, $section, $enrollment);
    }
    public function testWrongScopeAndActorCannotReuseAReview(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $r = $this->review($f);
        try {
            $this->service($s)->view($s->student($s->user())->id, $section, $enrollment);
            self::fail("A foreign student scope was accepted.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $this->confirm($f, $r);
    }

    public function testUnknownEarlierRegistrationQuantitiesAreNotInferredOrWritten(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section();
        $enrollment = EntityId::generate();
        $s->sql->insert("sis_enrollments", [
            "id" => $enrollment->value,
            "student_id" => $student->value,
            "section_id" => $section->id->value,
            "term_id" => $s->term->value,
            "status" => "A",
            "registered_at" => "2026-08-01T17:00:00Z",
            "status_at" => "2026-08-01T17:00:00Z",
            "version" => 0,
        ]);
        $s->sql->insert("sis_academic_credits", [
            "id" => EntityId::generate()->value,
            "student_id" => $student->value,
            "source_id" => $enrollment->value,
            "course_id" => $section->courseId->value,
            "term_id" => $s->term->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Fictional earlier course",
            "attempted_milli" => 3000,
            "completed_milli" => 0,
            "grade_points_milli" => 0,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        $before = $this->facts($s);
        $view = $this->service($s)->view($student, $section->id, $enrollment);
        self::assertNull($view["original_registered_milli"]);
        self::assertNull($view["current_registered_milli"]);
        self::assertFalse($view["can_correct"]);
        try {
            $this->service($s)->review(
                $student,
                $section->id,
                $enrollment,
                0,
                0,
                4500,
                "Fictional evidence cannot invent missing registration history.",
                true,
            );
            self::fail("Earlier quantities were inferred.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testClosedCorrectedAttemptsCannotChangeQuantityOrLoseTheirOriginalReceipt(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        $r = $this->review($f);
        $receipt = $this->confirm($f, $r);
        $s->gradeScale([
            [
                "code" => "W",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ],
        ]);
        $s->publishDropPolicy(
            $s->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([
                ["through" => null, "grade_code" => "W"],
            ]),
        );
        $s->clock->time = new \DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $s->service->drop($student, $section);
        $view = $this->service($s)->view($student, $section, $enrollment);
        self::assertFalse($view["can_correct"]);
        $root = $s->academicCredits()->decisionRecord($student, $section, $enrollment)[1];
        self::assertTrue($root->canReinstate());
        self::assertSame(
            ['original_milli' => 3000, 'registered_milli' => 4500, 'correction_count' => 1],
            $root->reinstatementRegisteredCredits(),
        );
        $before = $this->facts($s);
        self::assertSame($receipt, $this->confirm($f, $r));
        try {
            $this->service($s)->review(
                $student,
                $section,
                $enrollment,
                $view["expected_version"],
                $view["expected_position"],
                2500,
                "Fictional closed attempt.",
                true,
            );
            self::fail("A closed attempt accepted a fresh quantity correction.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testBoundsUnchangedQuantitiesAndFinalExpiryAreRejected(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
        foreach ([-1, 1000000, 3000] as $units) {
            $before = $this->facts($s);
            try {
                $this->review($f, $units);
                self::fail("An invalid registered quantity was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->facts($s));
            }
        }
        $r = $this->review($f);
        $before = $this->facts($s);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s): void {
                $s->clock->time = $s->clock->time->modify("+21 minutes");
            });
        try {
            $this->confirm($f, $r, $this->service($s, $audit));
            self::fail("An expired confirmation survived the required audit.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testPendingGradeCorrectionMustBeResolvedBeforeARegisteredQuantityChange(): void
    {
        $f = [$s, $student, $section, $enrollment] = $this->fixture();
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
                "min_score_milli" => 0,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $ledger = $s->academicCredits();
        $grades = array_column(
            $ledger->choices($student, $section, $enrollment)["items"],
            "id",
            "code",
        );
        $r = $ledger->review($student, $section, $enrollment, new EntityId($grades["B"]), 0);
        $ledger->commit($student, $section, $r["review_token"]);
        $ledger->saveGradeChangePolicy("approval", 0);
        $r = $ledger->review(
            $student,
            $section,
            $enrollment,
            new EntityId($grades["A"]),
            1,
            reason: "Fictional pending grade proposal.",
        );
        $ledger->commit($student, $section, $r["review_token"]);
        self::assertFalse($this->service($s)->view($student, $section, $enrollment)["can_correct"]);
        $before = $this->facts($s);
        try {
            $this->review($f);
            self::fail("A pending grade proposal was bypassed.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
    }
}
