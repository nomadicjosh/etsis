<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\GraduationFixture;

final class InternalTransferTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/GraduationFixture.php";
    }
    private function fixture(bool $confer = true): array
    {
        $g = new GraduationFixture();
        $s = $g->sis;
        if ($confer) {
            $g->confirm($g->review());
            $g->confirm($g->review("confer"));
        }
        $student = $g->curriculum->student;
        $target = EntityId::generate();
        $program = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "NEXT.UG",
            "title" => "Fictional receiving degree",
        ]);
        new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
            $s->sql,
        )->addAssignment(
            new \Domain\Student\StudentProgram(
                $target,
                $student,
                $program,
                $s->term,
            ),
        );
        $source = $s->sql->rows(
            "sis_academic_credits",
            ["student_id" => $student->value],
            1,
        )[0];
        $course = new EntityId($source["course_id"]);
        $version = $s->publishCourse($course);
        return [
            $g,
            $student,
            $target,
            [
                "source_credit_id" => $source["id"],
                "course_id" => $course->value,
                "course_version_id" => $version->value,
                "term_id" => $s->term->value,
                "credits_milli" => 3000,
                "grade_code" => "A",
            ],
        ];
    }
    private function confirm(
        GraduationFixture $g,
        EntityId $student,
        EntityId $target,
        array $r,
    ): array {
        return $g->sis
            ->internalTransfers()
            ->confirm(
                $student,
                $target,
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["request"],
                $r["reason"],
                $r["revision"],
                $r["review_token"],
            );
    }
    private function grant(
        GraduationFixture $g,
        EntityId $student,
        EntityId $target,
        array $request,
    ): array {
        return $this->confirm(
            $g,
            $student,
            $target,
            $g->sis
                ->internalTransfers()
                ->review(
                    $student,
                    $target,
                    "grant",
                    $request,
                    "Fictional receiving evaluation approved.",
                ),
        );
    }
    public function testReceivingDecisionRetainsSourceAndCreatesTransferWorkOnlyInTheNewPursuit(): void
    {
        [$g, $student, $target, $r] = $this->fixture();
        $s = $g->sis;
        $service = $s->internalTransfers();
        $original = $s->sql->one("sis_academic_credits", [
            "id" => $r["source_credit_id"],
        ]);
        $credential = $g->current()["credential"];
        self::assertCount(1, $service->candidates($student, $target)["items"]);
        $result = $this->grant($g, $student, $target, $r);
        $award = new EntityId($result["award_id"]);
        $show = $service->view($student, $target, $award);
        self::assertSame("transfer", $show["credit"]["credit_type"]);
        self::assertSame("2026-08-01", $show["completed_on"]);
        self::assertSame(
            $credential["id"],
            $show["source"]["source_credential_id"],
        );
        self::assertSame(
            $original,
            $s->sql->one("sis_academic_credits", [
                "id" => $r["source_credit_id"],
            ]),
        );
        self::assertSame($credential, $g->current()["credential"]);
        self::assertSame([], $s->sql->rows("sis_transfer_awards"));
        $inputs = $s
            ->inputVerifier()
            ->verify($student, new HistoryReadBudget());
        $received = $s
            ->workPursuits()
            ->forPursuit($inputs, $student, $target, new HistoryReadBudget());
        self::assertCount(1, $received->items);
        self::assertSame(
            "same_institution",
            $received->items[0]["transfer_source"],
        );
        self::assertSame($show["credit"]["id"], $received->items[0]["id"]);
        self::assertCount(0, $service->candidates($student, $target)["items"]);
        $this->expectException(ValidationException::class);
        $this->grant($g, $student, $target, $r);
    }
    public function testAnOrdinaryClosedOrActivePursuitDoesNotEstablishGraduation(): void
    {
        [$g, $student, $target, $r] = $this->fixture(false);
        self::assertSame(
            [],
            $g->sis->internalTransfers()->candidates($student, $target)[
                "items"
            ],
        );
        $this->expectException(ValidationException::class);
        $this->grant($g, $student, $target, $r);
    }
    public function testOriginalRetrySurvivesLaterRevocationAndChangedReceivingValuesAreRejected(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $service = $g->sis->internalTransfers();
        $r = $service->review(
            $student,
            $target,
            "grant",
            $request,
            "Fictional evaluation.",
        );
        $result = $this->confirm($g, $student, $target, $r);
        $g->confirm($g->review("revoke"));
        $g->sis->clock->time = $g->sis->clock->time->modify("+1 day");
        self::assertSame($result, $this->confirm($g, $student, $target, $r));
        $r["request"]["credits_milli"] = 2000;
        $this->expectException(ValidationException::class);
        $this->confirm($g, $student, $target, $r);
    }
    public function testVoidAmendAndReinstatePreserveLineageAndDoNotPermitExcessCredit(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $service = $g->sis->internalTransfers();
        $result = $this->grant($g, $student, $target, $request);
        $award = new EntityId($result["award_id"]);
        $source = $service->view($student, $target, $award)["source"];
        $amend = $service->review(
            $student,
            $target,
            "amend",
            [
                "award_id" => $award->value,
                "credits_milli" => 2000,
                "grade_code" => "A",
            ],
            "Fictional partial receiving credit.",
        );
        $this->confirm($g, $student, $target, $amend);
        $void = $service->review(
            $student,
            $target,
            "void",
            ["award_id" => $award->value],
            "Fictional withdrawal of evaluation.",
        );
        $this->confirm($g, $student, $target, $void);
        self::assertSame(
            "V",
            $service->view($student, $target, $award)["credit"]["status"],
        );
        self::assertSame(
            2000,
            $service->view($student, $target, $award)["retained_credits_milli"],
        );
        $restore = $service->review(
            $student,
            $target,
            "reinstate",
            [
                "award_id" => $award->value,
                "credits_milli" => 3000,
                "grade_code" => "A",
            ],
            "Fictional restored evaluation.",
        );
        $this->confirm($g, $student, $target, $restore);
        self::assertSame(
            $source,
            $service->view($student, $target, $award)["source"],
        );
        self::assertCount(
            4,
            $service->timeline($student, $target, $award)["items"],
        );
        $this->expectException(ValidationException::class);
        $service->review(
            $student,
            $target,
            "amend",
            [
                "award_id" => $award->value,
                "credits_milli" => 4000,
                "grade_code" => "A",
            ],
            "Cannot create unearned units.",
        );
    }
    public function testStaleSourceRevocationStopsConfirmationWithoutCreatingAnyReceivingCredit(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $service = $g->sis->internalTransfers();
        $review = $service->review(
            $student,
            $target,
            "grant",
            $request,
            "Fictional pending evaluation.",
        );
        $g->confirm($g->review("revoke"));
        try {
            $this->confirm($g, $student, $target, $review);
            self::fail("Revoked original credential was accepted.");
        } catch (ValidationException) {
            self::assertSame(
                [],
                $g->sis->sql->rows("sis_internal_transfer_awards"),
            );
        }
    }
    private function sharedServices(\SisFixture $s): array
    {
        $history = \EventStoreFixture::history($s->connection->queryBuilder());
        $credits = new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository(
            $s->sql,
        );
        $awards = new \Infrastructure\Persistence\Repository\SqlTransferAwardRepository(
            $s->sql,
            $s->gradeCatalog(),
        );
        return [
            new \Domain\Transfer\Service\TransferCourseService(
                $s->transferCredits(),
                $credits,
                $awards,
                $s->registration,
                new \Domain\Catalog\Service\PublishedCourseReader($history),
                $history,
                $s->transactions,
                $s->access,
                $s->dropCipher,
                $s->clock,
                $s->audit,
                new \Infrastructure\Persistence\Repository\SqlCourseSearchRepository(
                    $s->sql,
                ),
            ),
            new \Domain\Transfer\Service\TransferAcademicDetailsService(
                $s->transferCredits(),
                $credits,
                $awards,
                $s->registration,
                $history,
                $s->transactions,
                $s->access,
                $s->dropCipher,
                $s->clock,
                $s->audit,
            ),
        ];
    }
    public function testReceivingCourseAndPostingCorrectionsKeepActualSourceDatesAndLineage(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $result = $this->grant($g, $student, $target, $request);
        $award = new EntityId($result["award_id"]);
        $original = $s->internalTransfers()->view($student, $target, $award);
        [$courses, $periods] = $this->sharedServices($s);
        $course = EntityId::generate();
        $s->registration->addCourse(
            new \Domain\Catalog\Course(
                $course,
                "REC.301",
                "Fictional received subject",
                3000,
            ),
        );
        $version = $s->publishCourse($course);
        $review = $courses->review(
            $student,
            $award,
            $version,
            0,
            "2026-08-10",
            "Fictional receiving course correction.",
            $course,
        );
        $courses->commit($student, $award, $review["review_token"]);
        $fresh = $s->internalTransfers()->view($student, $target, $award);
        self::assertSame("REC.301", $fresh["credit"]["course_code"]);
        $term = $s->sql->one("sis_terms", ["id" => $s->term->value]);
        $term["id"] = EntityId::generate()->value;
        $term["code"] = "REC.26";
        $s->sql->insert("sis_terms", $term);
        $r = $periods->review(
            $student,
            $award,
            null,
            $fresh["credit"]["version"],
            "Fictional receiving posting correction.",
            $fresh["completed_on"],
            "2026-08-10",
            new EntityId($term["id"]),
        );
        $periods->commit($student, $award, $r["review_token"]);
        $periods->commit($student, $award, $r["review_token"]);
        $fresh = $s->internalTransfers()->view($student, $target, $award);
        self::assertSame($term["id"], $fresh["credit"]["term_id"]);
        self::assertSame($original["source"], $fresh["source"]);
        self::assertSame($original["completed_on"], $fresh["completed_on"]);
        $this->expectException(ValidationException::class);
        $periods->review(
            $student,
            $award,
            null,
            $fresh["credit"]["version"],
            "Cannot invent a newer completion.",
            "2026-08-02",
            "2026-08-10",
        );
    }
    public function testOwnerAndIncompleteStaffDutiesCannotEvaluateAndForeignPursuitsCannotRead(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $service = $s->internalTransfers();
        $s->access->staff = false;
        try {
            $service->candidates($student, $target);
            self::fail("Owner acquired registrar authority.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame([], $s->sql->rows("sis_internal_transfer_awards"));
        }
        $s->access->grants = ["sis:transfer:manage"];
        try {
            $this->grant($g, $student, $target, $request);
            self::fail("Student read duty was bypassed.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = true;
        $r = $this->grant($g, $student, $target, $request);
        $this->expectException(
            \Domain\Shared\Exception\AccessDeniedException::class,
        );
        $service->view(
            $student,
            $g->curriculum->assignment,
            new EntityId($r["award_id"]),
        );
    }
    public function testAProjectionFailureAfterJournalAndCreditWritesRollsBackTheWholeDecision(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $service = $s->internalTransfers();
        $review = $service->review(
            $student,
            $target,
            "grant",
            $request,
            "Fictional transactional evaluation.",
        );
        $names = [
            "sis_history_events",
            "sis_academic_credits",
            "sis_work_pursuit_scopes",
            "sis_internal_transfer_awards",
            "sis_audit",
        ];
        $before = [];
        foreach ($names as $name) {
            $before[$name] = $s->sql->rows($name);
        }
        $s->connection->pdo->exec(
            "CREATE TRIGGER internal_scope_failure BEFORE INSERT ON sis_work_pursuit_scopes BEGIN " .
                "SELECT RAISE(ABORT,'Fictional scope failure'); END",
        );
        $failed = false;
        try {
            $this->confirm($g, $student, $target, $review);
        } catch (\PDOException) {
            $failed = true;
        }
        self::assertTrue($failed);
        foreach ($before as $name => $rows) {
            self::assertSame($rows, $s->sql->rows($name));
        }
        $s->connection->pdo->exec("DROP TRIGGER internal_scope_failure");
        $this->confirm($g, $student, $target, $review);
        self::assertCount(1, $s->sql->rows("sis_internal_transfer_awards"));
    }
    public function testReceivedWorkCannotBeReattributedToAnotherPursuitOrOrdinarilyReused(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $r = $this->grant($g, $student, $target, $request);
        $record = $s
            ->internalTransfers()
            ->view($student, $target, new EntityId($r["award_id"]));
        $another = EntityId::generate();
        new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
            $s->sql,
        )->addAssignment(
            new \Domain\Student\StudentProgram(
                $another,
                $student,
                $s->program,
                $s->term,
            ),
        );
        $this->expectException(ValidationException::class);
        $s->workPursuits()->review(
            $student,
            new EntityId($record["credit"]["id"]),
            $another,
            "approve_reuse",
            "Cannot bypass a receiving decision.",
        );
    }

    public function testReceivingGradeAndResidencyDoNotCopyTheOriginalDegreeGpa(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $s->gradeScale([
            [
                "code" => "TR",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
        ]);
        $request["grade_code"] = "TR";
        $this->grant($g, $student, $target, $request);
        $input = $s
            ->workPursuits()
            ->forPursuit(
                $s->inputVerifier()->verify($student, new HistoryReadBudget()),
                $student,
                $target,
                new HistoryReadBudget(),
            );
        $attempts = array_map(
            [
                \Domain\DegreeAudit\ValueObject\PolicyAttempt::class,
                "fromAcademicWork",
            ],
            $input->items,
        );
        $policy = new \Domain\DegreeAudit\ValueObject\AuditCalculationPolicy(
            \Domain\DegreeAudit\ValueObject\RepeatCreditPolicy::HighestEarned,
            \Domain\DegreeAudit\ValueObject\RepeatGpaPolicy::AllEligible,
            \Domain\DegreeAudit\ValueObject\TransferGpaPolicy::IncludeEligible,
        );
        $totals = new \Domain\DegreeAudit\Service\AcademicPolicyEvaluator()->evaluate(
            $attempts,
            $policy,
        );
        self::assertSame(3000, $totals->earnedMilli);
        self::assertSame(0, $totals->residentMinimumMilli);
        self::assertSame(0, $totals->gpa->creditsMilli);
        self::assertNull($totals->gpa->milli());
        self::assertArrayNotHasKey("earned_ceus", $input->items[0]);
    }
    public function testFormalReceivingCreditSuppliesAPrerequisiteThatOrdinaryGraduatedWorkCannot(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $course = EntityId::generate();
        $s->registration->addCourse(
            new \Domain\Catalog\Course(
                $course,
                "ADV.401",
                "Fictional advanced work",
                3000,
            ),
        );
        $s->registration->addPrerequisite(
            $course,
            new EntityId($request["course_id"]),
        );
        $s->publishCourse($course);
        $section = new \Domain\Registration\Section(
            EntityId::generate(),
            $course,
            $s->term,
            $s->access->actor,
            "ADV.01",
            10,
            true,
            true,
            new \Domain\Finance\ValueObject\Money(0, "USD"),
        );
        $s->registration->addSection($section);
        $blocked = false;
        try {
            $s->service->register($student, $section->id, true, $target);
        } catch (\DomainException | ValidationException) {
            $blocked = true;
        }
        self::assertTrue($blocked);
        $this->grant($g, $student, $target, $request);
        self::assertSame(
            "A",
            $s->service->register($student, $section->id, true, $target)->status
                ->value,
        );
    }
    public function testReceivingProjectionsRebuildFromTheSameJournalWithoutNewAcademicOrFinancialEvents(): void
    {
        [$g, $student, $target, $request] = $this->fixture();
        $s = $g->sis;
        $result = $this->grant($g, $student, $target, $request);
        $award = new EntityId($result["award_id"]);
        $review = $s
            ->internalTransfers()
            ->review(
                $student,
                $target,
                "amend",
                [
                    "award_id" => $award->value,
                    "credits_milli" => 2000,
                    "grade_code" => "A",
                ],
                "Fictional retained partial award.",
            );
        $this->confirm($g, $student, $target, $review);
        [$root, $facts] = $s
            ->transferCredits()
            ->decisionRecord($student, $award, new HistoryReadBudget());
        $credit = new EntityId($facts["id"]);
        $scope = $s->workPursuitLedger()->load($credit);
        $publication = new \Domain\Catalog\Service\PublishedCourseReader(
            \EventStoreFixture::history($s->connection->queryBuilder()),
        )->version(
            new EntityId($facts["course_id"]),
            new EntityId($root->courseVersion()),
            new HistoryReadBudget(),
        );
        $events = $s->sql->rows("sis_history_events");
        $finance = $s->financialEvents();
        $s->transactions->run(function () use (
            $s,
            $award,
            $credit,
            $root,
            $scope,
            $publication,
        ): void {
            $s->sql->execute(
                "DELETE FROM sis_internal_transfer_awards WHERE id = ?",
                [$award->value],
            );
            $s->sql->execute(
                "DELETE FROM sis_transfer_credit_streams WHERE id = ?",
                [$award->value],
            );
            $s->sql->execute(
                "DELETE FROM sis_work_pursuit_scopes WHERE id = ?",
                [$credit->value],
            );
            $s->sql->execute(
                "DELETE FROM sis_credit_course_versions WHERE id = ?",
                [$credit->value],
            );
            $s->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [
                $credit->value,
            ]);
            new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository(
                $s->sql,
            )->create($root->source(), $root->credit(), $publication);
            new \Infrastructure\Persistence\Repository\SqlWorkPursuitRepository(
                $s->sql,
            )->save($scope, 0);
        });
        self::assertSame(
            2000,
            $s->internalTransfers()->view($student, $target, $award)["credit"][
                "completed_milli"
            ],
        );
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame($finance, $s->financialEvents());
    }
}
