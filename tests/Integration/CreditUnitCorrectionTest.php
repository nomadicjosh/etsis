<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\AcademicRecord\ValueObject\CreditUnitCorrection;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class CreditUnitCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(bool $withdrawn = false, bool $earlier = false): array
    {
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
        ]);
        $grades = array_column($f->sql->rows("sis_grades"), "id", "code");
        $student = $f->student($f->user())->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        if ($earlier) {
            $enrollment = EntityId::generate();
            $credit = EntityId::generate();
            $f->sql->insert("sis_enrollments", [
                "id" => $enrollment->value,
                "student_id" => $student->value,
                "section_id" => $section->value,
                "term_id" => $f->term->value,
                "status" => $withdrawn ? "W" : "A",
                "registered_at" => "2026-08-01T17:00:00Z",
                "status_at" => "2026-09-02T17:00:00Z",
                "version" => 1,
            ]);
            $f->sql->insert("sis_academic_credits", [
                "id" => $credit->value,
                "student_id" => $student->value,
                "source_id" => $enrollment->value,
                "course_id" => $f->registration->section($section)->courseId->value,
                "term_id" => $f->term->value,
                "credit_type" => "institutional",
                "course_code" => "OLD.101",
                "course_title" => "Fictional earlier course",
                "attempted_milli" => 3000,
                "completed_milli" => $withdrawn ? 1000 : 3000,
                "grade_points_milli" => $withdrawn ? 9999 : 3000,
                "grade_id" => $grades["D"],
                "status" => $withdrawn ? "W" : "A",
                "version" => 2,
            ]);
        } else {
            $enrollment = $f->service->register($student, $section)->id;
            if ($withdrawn) {
                $f->publishDropPolicy(
                    $f->term,
                    -1,
                    new DropWindows([["through" => null, "grade_code" => "D"]]),
                );
                $f->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
                $f->service->drop($student, $section);
            } else {
                $r = $f
                    ->academicCredits()
                    ->review($student, $section, $enrollment, new EntityId($grades["D"]), 0);
                $f->academicCredits()->commit($student, $section, $r["review_token"]);
            }
            $credit = new EntityId(
                $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
            );
        }
        return [$f, $student, $section, $enrollment, $credit, $grades];
    }

    private function review(
        array $fixture,
        int $units = 4000,
        string $grade = "A",
        bool $reconcile = false,
    ): array {
        [$f, $student, $section, $enrollment, $credit, $grades] = $fixture;
        $version = $f->sql->one("sis_academic_credits", ["id" => $credit->value])["version"];
        return $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                new EntityId($grades[$grade]),
                $version,
                reason: "Fictional institutional evidence supports the credit correction.",
                reconcileQuantities: $reconcile,
                attemptedMilli: $units,
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
            "sis_enrollments",
            "sis_audit",
        ];
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = $f->sql->rows($table);
        }
        return $out;
    }

    public function testGradeAndCreditUnitsAreOneDecisionWithUnchangedRegistrationCatalogAndFinance(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $grades] = $fixture;
        $before = $this->snapshot($f);
        $money = $f->financialEvents();
        $review = $this->review($fixture);
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(3000, $review["credit_unit_summary"]["previous_completed_milli"]);
        self::assertSame(4000, $review["credit_unit_summary"]["completed_milli"]);
        self::assertSame(16000, $review["credit_unit_summary"]["grade_points_milli"]);
        $receipt = $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame(4000, $facts["credit"]["attempted_milli"]);
        self::assertSame(4000, $facts["credit"]["completed_milli"]);
        self::assertSame(16000, $facts["credit"]["grade_points_milli"]);
        self::assertSame($grades["A"], $facts["credit"]["grade_id"]);
        self::assertSame(2, $facts["credit"]["version"]);
        self::assertSame($before["sis_enrollments"], $f->sql->rows("sis_enrollments"));
        self::assertSame($money, $f->financialEvents());
        self::assertSame(
            3000,
            $f->registration->course($f->registration->section($section)->courseId)->creditsMilli,
        );
        self::assertSame(
            $receipt,
            $f->academicCredits()->commit($student, $section, $review["review_token"]),
        );
    }

    public function testSeparatePermissionIsRequiredForReviewConfirmationAndAcceptedRetries(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment] = $fixture;
        $review = $this->review($fixture);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        self::assertFalse(
            $f->academicCredits()->choices($student, $section, $enrollment)["can_correct_credit_units"],
        );
        $before = $this->snapshot($f);
        $actions = [
                fn() => $this->review($fixture),
                fn() => $f->academicCredits()->commit($student, $section, $review["review_token"]),
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail("Ordinary grade amendment authority changed credit units.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $f->access->grants[] = CreditUnitCorrection::PERMISSION;
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $before = $this->snapshot($f);
        try {
            $f->academicCredits()->commit($student, $section, $review["review_token"]);
            self::fail("A retry bypassed current credit-correction authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testIndependentApprovalPreservesOriginalGradeAndUnitsUntilAnAuthorizedDecision(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, , $credit] = $fixture;
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $before = $f->sql->rows("sis_academic_credits");
        $receipt = $ledger->commit($student, $section, $this->review($fixture)["review_token"]);
        $id = new EntityId($receipt["operation_id"]);
        self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        try {
            $ledger->reviewGradeChange($id, "approved", "Fictional self approval.");
            self::fail("The requester approved their own unit correction.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        }
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
        self::assertFalse($ledger->gradeChangeRequest($id)["can_approve"]);
        self::assertTrue($ledger->gradeChangeRequest($id)["can_reject"]);
        try {
            $ledger->reviewGradeChange($id, "approved", "Fictional review.");
            self::fail("Ordinary approval duty changed credit units.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        }
        $f->access->grants[] = CreditUnitCorrection::PERMISSION;
        $decision = $ledger->reviewGradeChange($id, "approved", "Fictional independent review.");
        $accepted = $ledger->resolveGradeChange($id, $decision["review_token"]);
        self::assertSame(16000, $ledger->auditFacts($credit, $student)["credit"]["grade_points_milli"]);
        self::assertSame($accepted, $ledger->resolveGradeChange($id, $decision["review_token"]));
        $request = $ledger->gradeChangeRequest($id);
        self::assertSame(3000, $request["credit_unit_summary"]["previous_attempted_milli"]);
        self::assertSame(4000, $request["credit_unit_summary"]["attempted_milli"]);
    }

    public function testWithdrawnUnitCorrectionsAndOlderReconciliationKeepZeroEarnedCreditAndCharges(): void
    {
        foreach ([false, true] as $earlier) {
            $fixture = $this->fixture(withdrawn: true, earlier: $earlier);
            [$f, $student, $section, $enrollment, $credit] = $fixture;
            $money = $f->financialEvents();
            $enrollments = $f->sql->rows("sis_enrollments");
            $review = $this->review($fixture, 4000, "D", $earlier);
            self::assertSame(0, $review["credit_unit_summary"]["completed_milli"]);
            if ($earlier) {
                self::assertSame(4000, $review["withdrawal_quantities"]["expected_grade_points_milli"]);
            }
            $f->academicCredits()->commit($student, $section, $review["review_token"]);
            $facts = $f->academicCredits()->auditFacts($credit, $student);
            self::assertSame("W", $facts["credit"]["status"]);
            self::assertSame(0, $facts["credit"]["completed_milli"]);
            self::assertSame(4000, $facts["credit"]["grade_points_milli"]);
            self::assertSame($money, $f->financialEvents());
            self::assertSame($enrollments, $f->sql->rows("sis_enrollments"));
            self::assertFalse(
                $f->academicCredits()->choices($student, $section, $enrollment)[
                    "requires_quantity_reconciliation"
                ],
            );
            self::assertSame($earlier, $facts["earlier_history_unknown"]);
        }
    }

    public function testZeroCreditCorrectionIsExplicitAndOriginalReceiptDoesNotUndoLaterUnits(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, , $credit] = $fixture;
        $first = $this->review($fixture);
        $receipt = $f->academicCredits()->commit($student, $section, $first["review_token"]);
        $zero = $this->review($fixture, 0);
        $f->academicCredits()->commit($student, $section, $zero["review_token"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $this->snapshot($f);
        self::assertSame($receipt, $f->academicCredits()->commit($student, $section, $first["review_token"]));
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(
            0,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["completed_milli"],
        );
        self::assertSame(
            0,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["grade_points_milli"],
        );
    }

    public function testInvalidUnchangedStaleAndExpiredReviewsCannotChangeFacts(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section] = $fixture;
        $before = $this->snapshot($f);
        foreach ([-1, 1000000, 3000] as $units) {
            try {
                $this->review($fixture, $units);
                self::fail("Invalid or unchanged units were accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $old = $this->review($fixture);
        $other = $this->review($fixture, 2000);
        $f->academicCredits()->commit($student, $section, $other["review_token"]);
        $before = $this->snapshot($f);
        try {
            $f->academicCredits()->commit($student, $section, $old["review_token"]);
            self::fail("A stale correction replaced later credit units.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $expired = $this->review($fixture, 5000);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        try {
            $f->academicCredits()->commit($student, $section, $expired["review_token"]);
            self::fail("An expired correction changed credit units.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testAuditFailureLatePermissionLossAndExpiryRollBackCombinedGradeAndUnits(): void
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
                self::fail("A failed check left changed units or grades behind.");
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testRejectAndCancelLeaveBothGradeAndUnitsUnchanged(): void
    {
        foreach (["rejected", "cancelled"] as $outcome) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $ledger = $f->academicCredits();
            $ledger->saveGradeChangePolicy("approval", 0);
            $before = $f->sql->rows("sis_academic_credits");
            $receipt = $ledger->commit($student, $section, $this->review($fixture)["review_token"]);
            $id = new EntityId($receipt["operation_id"]);
            if ($outcome === "rejected") {
                $f->access->actor = $f->user();
            }
            $f->access->staff = false;
            $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
            $decision = $ledger->reviewGradeChange($id, $outcome, "Fictional unresolved credit evidence.");
            $ledger->resolveGradeChange($id, $decision["review_token"]);
            self::assertSame($before, $f->sql->rows("sis_academic_credits"));
        }
    }

    public function testApprovalRechecksCreditDutyAndRollsBackAfterLateLoss(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section] = $fixture;
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $receipt = $ledger->commit($student, $section, $this->review($fixture)["review_token"]);
        $f->access->actor = $f->user();
        $id = new EntityId($receipt["operation_id"]);
        $decision = $ledger->reviewGradeChange($id, "approved", "Fictional approved evidence.");
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:approve"];
        $before = $this->snapshot($f);
        try {
            $ledger->resolveGradeChange($id, $decision["review_token"]);
            self::fail("Revoked unit duty was bypassed at approval.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->access->grants[] = CreditUnitCorrection::PERMISSION;
        $audit = new class ($f) implements AuditTrail {
            public function __construct(private SisFixture $f)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->f->access->grants = [
                    "sis:gradebook:amend",
                    "sis:student:read",
                    "sis:gradebook:approve",
                ];
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $f->academicCredits($audit)->resolveGradeChange($id, $decision["review_token"]);
            self::fail("Late unit-duty loss left a changed grade or credit quantity.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testInitialGradeAndUnknownWithdrawnGradeCannotCarryUnitCorrections(): void
    {
        $fixture = $this->fixture(earlier: true);
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $f->sql->update(
            "sis_academic_credits",
            ["grade_id" => null, "completed_milli" => 0, "grade_points_milli" => 0],
            ["id" => $credit->value],
        );
        $before = $this->snapshot($f);
        self::assertFalse(
            $f->academicCredits()->choices($student, $section, $enrollment)["can_correct_credit_units"],
        );
        try {
            $this->review($fixture);
            self::fail("An ungraded record entered the unit-correction workflow.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testMigrationIsRetrySafeAndNeverGrantsAnExistingRoleTheNewDuty(): void
    {
        [$f] = $this->fixture();
        $tables = [
            "sis_access_role_permissions",
            "sis_access_user_permissions",
            "sis_access_user_roles",
            "sis_history_events",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $migration = new \AddSisCreditUnitCorrectionPermission("retry");
        $migration->setObjectMap(
            new \Qubus\Support\Container\ObjectStorageMap([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        $permissions = $f->sql->rows("sis_access_permissions", ["name" => CreditUnitCorrection::PERMISSION]);
        self::assertCount(1, $permissions);
        self::assertSame(1, (int) $permissions[0]["is_system"]);
        foreach ($tables as $table) {
            self::assertSame($before[$table], $f->sql->rows($table));
        }
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permissions[0]["id"]]);
        $before = $f->sql->rows("sis_access_permissions");
        try {
            $migration->up();
            self::fail("The migration replaced an existing custom permission.");
        } catch (\LogicException) {
            self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        }
    }

    public function testPreparedTranscriptBecomesStaleWithoutChangingItsProtectedOriginal(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        $t = new \TranscriptFixture();
        $request = $t->request();
        $t->postGrade($request);
        $f = $t->sis;
        $student = new EntityId($t->repository->request($request)["student_id"]);
        $enrollment = $f->sql->rows("sis_enrollments", ["student_id" => $student->value])[0];
        $credit = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment["id"]]);
        $renderer = $this->createStub(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturn("<p>Fictional protected transcript</p>");
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional original");
        $renderer->method("html")->willReturn("Fictional transcript");
        $service = $t->service($renderer);
        $prepared = $service->review($request);
        $document = new EntityId($prepared["operation_id"]);
        $service->prepare($request, $document, $prepared["revision"], true, $prepared["review_token"]);
        $bytes = $service->pdf($request, $document);
        $section = new EntityId($enrollment["section_id"]);
        $review = $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                new EntityId($enrollment["id"]),
                new EntityId($credit["grade_id"]),
                $credit["version"],
                reason: "Fictional verified unit correction.",
                attemptedMilli: 4000,
            );
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        self::assertTrue($service->view($request, $document)["academic_changed"]);
        self::assertSame($bytes, $service->pdf($request, $document));
        self::assertSame(4000, $service->review($request)["snapshot"]["credits"][0]["attempted_milli"]);
    }
}
