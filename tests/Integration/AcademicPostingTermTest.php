<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AcademicPostingTermTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(): array
    {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "counts_gpa" => true,
                "passes" => true,
            ],
        ]);
        $student = $f->student($f->user())->id;
        $section = $f->section(faculty: $f->access->actor)->id;
        $enrollment = $f->service->register($student, $section)->id;
        $choices = $f->academicCredits()->choices($student, $section, $enrollment);
        $grade = new EntityId($choices["items"][0]["id"]);
        $review = $f->academicCredits()->review($student, $section, $enrollment, $grade, 0);
        $f->academicCredits()->commit($student, $section, $review["review_token"]);
        $credit = new EntityId(
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        $target = EntityId::generate();
        $term = $f->sql->one("sis_terms", ["id" => $f->term->value]);
        $f->sql->insert(
            "sis_terms",
            array_replace($term, [
                "id" => $target->value,
                "code" => "26/SP",
                "starts_on" => "2026-01-04",
                "ends_on" => "2026-05-20",
                "add_drop_ends_on" => "2026-01-20",
            ]),
        );
        return [$f, $student, $section, $enrollment, $credit, $target, $grade];
    }

    private function review(array $fixture, ?string $start = null, ?string $completion = null): array
    {
        [$f, $student, $section, $enrollment, $credit, $target] = $fixture;
        $version = $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"];
        return $f
            ->academicDates()
            ->reviewDetails(
                $student,
                $section,
                $enrollment,
                $start,
                $completion,
                $target,
                $version,
                "Fictional verified academic placement.",
                "2026-08-10",
            );
    }

    private function snapshot(SisFixture $f): array
    {
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_credit_posting_terms",
            "sis_enrollments",
            "sis_audit",
        ];
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = $f->sql->rows($table);
        }
        return $out;
    }

    public function testPostingCorrectionPreservesRegistrationMoneyGradeAndSourceChronology(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $grade] = $fixture;
        $before = $this->snapshot($f);
        $money = $f->financialEvents();
        $review = $this->review($fixture);
        self::assertSame(["posting_term_id"], $review["changes"]);
        self::assertNull($review["previous_term_code"]);
        self::assertSame($before, $this->snapshot($f));
        $receipt = $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame($target->value, $facts["credit"]["term_id"]);
        self::assertSame($f->term->value, $facts["registration_term_id"]);
        self::assertSame("26/SP", $facts["retained_term_code"]);
        self::assertSame($grade->value, $facts["credit"]["grade_id"]);
        self::assertSame(3000, $facts["credit"]["completed_milli"]);
        self::assertNull($facts["academic_on"]);
        self::assertNull($facts["completed_on"]);
        self::assertSame($before["sis_enrollments"], $f->sql->rows("sis_enrollments"));
        self::assertSame($money, $f->financialEvents());
        $inputs = $f->inputVerifier()->verify($student, new HistoryReadBudget());
        self::assertSame($target->value, $inputs->items[0]["term_id"]);
        self::assertSame("26/SP", $inputs->items[0]["term_code"]);
        self::assertSame(
            $receipt,
            $f->academicDates()->commitDetails($student, $section, $review["review_token"]),
        );
        self::assertSame(
            $target->value,
            $f->academicCredits()->choices($student, $section, $enrollment)["posting_term_id"],
        );
    }

    public function testPostingAndBothDatesCommitTogetherAndLaterGradeCorrectionsUseOriginalEnrollment(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $grade] = $fixture;
        $review = $this->review($fixture, "2026-01-04", "2026-05-12");
        $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame(4, $facts["credit"]["version"]);
        self::assertSame("2026-01-04", $facts["academic_on"]);
        self::assertSame("2026-05-12", $facts["completed_on"]);
        $gradeReview = $f
            ->academicCredits()
            ->review(
                $student,
                $section,
                $enrollment,
                $grade,
                4,
                reason: "Fictional later grade verification.",
            );
        $f->academicCredits()->commit($student, $section, $gradeReview["review_token"]);
        self::assertSame(
            $target->value,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["term_id"],
        );
        $laterDate = $f
            ->academicDates()
            ->review(
                $student,
                $section,
                $enrollment,
                "2026-05-13",
                5,
                "Fictional later completion evidence.",
            );
        $f->academicDates()->commit($student, $section, $laterDate["review_token"]);
        self::assertSame("2026-05-13", $f->academicCredits()->auditFacts($credit, $student)["completed_on"]);
    }

    public function testPostingDutyIsSeparateFromDateAndGradeCorrectionAuthority(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment] = $fixture;
        $review = $this->review($fixture);
        $before = $this->snapshot($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        self::assertFalse(
            $f->academicCredits()->choices($student, $section, $enrollment)["can_correct_posting_term"],
        );
        $actions = [
            fn() => $this->review($fixture),
            fn() => $f->academicDates()->commitDetails($student, $section, $review["review_token"]),
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail("Ordinary grade/date authority changed academic placement.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testTargetRenameMakesReviewStaleButLaterRenamesPreserveAcceptedLabel(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target] = $fixture;
        $review = $this->review($fixture);
        $f->sql->update("sis_terms", ["code" => "26/SPRING"], ["id" => $target->value]);
        $before = $this->snapshot($f);
        try {
            $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
            self::fail("A renamed target used an earlier review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $review = $this->review($fixture);
        $receipt = $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        $f->sql->update("sis_terms", ["code" => "Current reference label"], ["id" => $target->value]);
        self::assertSame(
            "26/SPRING",
            $f->academicCredits()->auditFacts($credit, $student)["retained_term_code"],
        );
        $inputs = $f->inputVerifier()->verify($student, new HistoryReadBudget());
        self::assertSame("26/SPRING", $inputs->items[0]["term_code"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame(
            $receipt,
            $f->academicDates()->commitDetails($student, $section, $review["review_token"]),
        );
    }

    public function testAllThreeFactsRollBackOnAuditFailurePermissionLossOrExpiry(): void
    {
        foreach (["audit", "authority", "expiry"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $review = $this->review($fixture, "2026-01-04", "2026-05-12");
            $before = $this->snapshot($f);
            $audit = new class ($f, $failure) implements \Domain\Audit\Repository\AuditTrail {
                public int $calls = 0;
                public function __construct(private SisFixture $f, private string $failure)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if (++$this->calls !== 3) {
                        return;
                    }
                    if ($this->failure === "audit") {
                        throw new \RuntimeException("Fictional third audit failure.");
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
            try {
                $f->academicDates($audit)->commitDetails($student, $section, $review["review_token"]);
                self::fail("An interrupted combined correction committed.");
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame(3, $audit->calls);
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testPostingProjectionMustMatchRetainedHistory(): void
    {
        foreach (["missing", "changed"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section, $enrollment, $credit] = $fixture;
            $review = $this->review($fixture);
            $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
            if ($failure === "missing") {
                $f->sql->execute("DELETE FROM sis_credit_posting_terms WHERE id = ?", [$credit->value]);
            } else {
                $f->sql->update(
                    "sis_credit_posting_terms",
                    ["term_code" => "Unapproved label"],
                    ["id" => $credit->value],
                );
            }
            try {
                $f->academicCredits()->auditFacts($credit, $student);
                self::fail("A changed posting projection was trusted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testExpiredStaleAndDifferentlyScopedReviewsNeverWrite(): void
    {
        foreach (["expired", "stale", "student", "actor", "token_kind"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section, $enrollment, $credit] = $fixture;
            $review = $this->review($fixture);
            if ($failure === "expired") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
            if ($failure === "stale") {
                $later = $f
                    ->academicDates()
                    ->review($student, $section, $enrollment, "2026-08-01", 1, "Fictional later evidence.");
                $f->academicDates()->commit($student, $section, $later["review_token"]);
            }
            if ($failure === "actor") {
                $f->access->actor = EntityId::generate();
            }
            $before = $this->snapshot($f);
            try {
                if ($failure === "token_kind") {
                    $f->academicDates()->commitChronology($student, $section, $review["review_token"]);
                } else {
                    $f->academicDates()->commitDetails(
                        $failure === "student" ? EntityId::generate() : $student,
                        $section,
                        $review["review_token"],
                    );
                }
                self::fail("An invalid academic review committed.");
            } catch (
                AccessDeniedException | ConcurrentModificationException | \Domain\Shared\Exception\ValidationException
            ) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testRevertingPlacementRetainsBothDecisionsAndOriginalRetryDoesNotUndoIt(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit] = $fixture;
        $review = $this->review($fixture);
        $receipt = $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        $fixture[5] = $f->term;
        $back = $this->review($fixture);
        $f->academicDates()->commitDetails($student, $section, $back["review_token"]);
        $before = $this->snapshot($f);
        self::assertSame(
            $receipt,
            $f->academicDates()->commitDetails($student, $section, $review["review_token"]),
        );
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(
            $f->term->value,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["term_id"],
        );
    }

    public function testMigrationIsRetrySafeAndAddsNoRoleOrUserGrants(): void
    {
        [$f] = $this->fixture();
        $before = $this->snapshot($f);
        $grants = $f->sql->rows("sis_access_role_permissions");
        $migration = new \CreateSisAcademicPostingTerms("retry");
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
        self::assertSame($before, $this->snapshot($f));
        self::assertSame($grants, $f->sql->rows("sis_access_role_permissions"));
        $permissions = $f->sql->rows("sis_access_permissions", ["name" => "sis:academic:placement:amend"]);
        self::assertCount(1, $permissions);
        self::assertSame(1, (int) $permissions[0]["is_system"]);
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permissions[0]["id"]]);
        $before = $f->sql->rows("sis_access_permissions");
        try {
            $migration->up();
            self::fail("An existing custom permission was replaced.");
        } catch (\LogicException) {
            self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        }
    }
    public function testReportsKeepCorrectedPostingAndOriginalRegistrationTermsDistinct(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section] = $fixture;
        $review = $this->review($fixture);
        $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        $query = new \Domain\Reporting\ValueObject\ReportQuery([
            "dataset" => "credits", "columns" => [
                ["field" => "retained_posting_term_code", "label" => "Posting", "aggregate" => "none"],
                ["field" => "original_registration_term_code", "label" => "Registration", "aggregate" => "none"],
            ], "joins" => [], "filters" => null, "having" => null, "distinct" => false, "sort" => [],
        ], \Infrastructure\Reporting\AcademicReportSchema::datasets()["credits"]);
        $rows = new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql)->execute($query, 0);
        self::assertSame("26/SP", $rows[0]["c0"]);
        self::assertSame($f->sql->one("sis_terms", ["id" => $f->term->value])["code"], $rows[0]["c1"]);
    }

    public function testPreparedTranscriptRetainsItsOriginalAfterPostingCorrection(): void
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
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional original");
        $renderer->method("html")->willReturn("Fictional transcript");
        $service = $t->service($renderer);
        $prepared = $service->review($request);
        $document = new EntityId($prepared["operation_id"]);
        $service->prepare($request, $document, $prepared["revision"], true, $prepared["review_token"]);
        $bytes = $service->pdf($request, $document);
        $target = EntityId::generate();
        $term = $f->sql->one("sis_terms", ["id" => $credit["term_id"]]);
        $f->sql->insert("sis_terms", array_replace($term, ["id" => $target->value, "code" => "26/POST"]));
        $section = new EntityId($enrollment["section_id"]);
        $review = $f->academicDates()->reviewDetails(
            $student,
            $section,
            new EntityId($enrollment["id"]),
            null,
            null,
            $target,
            $credit["version"],
            "Fictional verified term correction.",
            "2026-08-10"
        );
        $f->academicDates()->commitDetails($student, $section, $review["review_token"]);
        self::assertTrue($service->view($request, $document)["academic_changed"]);
        self::assertSame($bytes, $service->pdf($request, $document));
        self::assertSame("26/POST", $service->review($request)["snapshot"]["credits"][0]["term_code"]);
    }

    public function testPendingGradeReviewPreventsPostingCorrectionWithoutChangingAnyFacts(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $grade] = $fixture;
        $ledger = $f->academicCredits();
        $ledger->saveGradeChangePolicy("approval", 0);
        $review = $ledger->review(
            $student,
            $section,
            $enrollment,
            $grade,
            1,
            reason: "Fictional pending grade correction."
        );
        $ledger->commit($student, $section, $review["review_token"]);
        $before = $this->snapshot($f);
        self::assertFalse($ledger->choices($student, $section, $enrollment)["can_correct_posting_term"]);
        try {
            $this->review($fixture);
            self::fail("Academic placement changed during pending grade review.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testProjectionDdlCompilesAcrossTheFiveFrameworkDialectsWithoutHistoricalWrites(): void
    {
        [$f] = $this->fixture();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(\Qubus\Expressive\Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema->method("create")->willReturnCallback(static function (
                string $name,
                callable $callback,
            ) use (&$definitions): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $callback($table);
                $definitions[] = $table;
            });
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection->method("queryBuilder")->willReturn($f->connection->queryBuilder());
            $connection->method("quoteIdentifier")->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \CreateSisAcademicPostingTerms("dialect");
            $migration->setObjectMap(new \ArrayObject(["phpmig.adapter" =>
                new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, "migration")]));
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            self::assertCount(1, $definitions);
            $sql = json_encode(new $class($connection)->create($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("sis_credit_posting_terms", $sql);
            self::assertStringContainsString("sis_academic_credits", $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
}
