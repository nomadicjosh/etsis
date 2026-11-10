<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentPrimaryProgramService;
use Domain\Student\Service\StudentRecordService;
use Domain\Student\Student;
use Domain\Student\StudentPrimaryProgram;
use Domain\Student\StudentProgram;
use Domain\Student\ValueObject\StudentPrimaryProgramId;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class StudentPrimaryProgramHistoryTest extends TestCase
{
    private function records(SisFixture $f): StudentRecordService
    {
        return new StudentRecordService(
            new SqlStudentRecordRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->activityLedger(),
            $f->programStatusLedger(),
            $f->primaryProgramLedger(),
        );
    }
    private function pursuit(SisFixture $f, ?EntityId $student = null): StudentProgram
    {
        $student ??= $f->student()->id;
        $program = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "FIX." . substr($program->value, -6),
            "title" => "Fictional arts program",
        ]);
        // Earlier, untracked pursuit fixture for primary-history isolation; new-assignment integration is tested separately.
        $id = EntityId::generate();
        new SqlStudentRecordRepository($f->sql)->addAssignment(new StudentProgram($id, $student, $program, $f->term));
        return new SqlStudentRecordRepository($f->sql)->assignment($id);
    }
    private function initial(SisFixture $f, StudentProgram $p): StudentProgram
    {
        return $f->programStatusLedger()->primaryAssignment($f->admissions->student($p->studentId));
    }
    private function review(SisFixture $f, StudentProgram $p): array
    {
        return $f
            ->primaryProgramService()
            ->review(
                $p->studentId,
                $p->id,
                $this->records($f)->view($p->studentId)["revision"],
                "Fictional protected primary selection reason.",
            );
    }
    private function select(SisFixture $f, StudentProgram $p): array
    {
        return $f
            ->primaryProgramService()
            ->commit($p->studentId, $p->id, $this->review($f, $p)["review_token"]);
    }
    public function testReviewAndHistoryDoNotWriteEventsAndTheFirstSelectionRetainsAnObservation(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        self::assertFalse($f->primaryProgramService()->history($p->studentId)["history_recorded"]);
        $preview = $this->review($f, $p);
        self::assertSame($initial->id->value, $preview["before"]["assignment_id"]);
        self::assertSame($p->id->value, $preview["after"]["assignment_id"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_primary_program_streams"));
        $receipt = $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]);
        self::assertSame(2, $receipt["version"]);
        self::assertSame($p->programId->value, $f->admissions->student($p->studentId)->programId->value);
        $page = $f->primaryProgramService()->history($p->studentId);
        self::assertSame(["observation", "selection"], array_column($page["items"], "kind"));
        self::assertTrue($page["earlier_history_unknown"]);
        self::assertSame($initial->id->value, $page["items"][0]["selection"]["assignment_id"]);
        self::assertSame($p->id->value, $page["items"][1]["selection"]["assignment_id"]);
    }
    public function testReplayPreservesIdentityOriginalEntryTermAcademicCreditsAndPostedCharges(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $before = $f->admissions->student($p->studentId);
        $f->service->register($p->studentId, $f->section(10, 1500)->id);
        $credits = $f->sql->rows("sis_academic_credits");
        $money = $f->financialEvents();
        $catalogs = $f->sql->rows("sis_student_catalogs");
        $programs = $f->sql->rows("sis_student_programs");
        $this->select($f, $p);
        $root = StudentPrimaryProgram::root(new StudentPrimaryProgramId($p->studentId->value));
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new StudentPrimaryProgramId($p->studentId->value),
        );
        foreach ($events as $e) {
            $root->acceptHistorical($e);
        }
        self::assertSame($p->id->value, $root->selection()["assignment_id"]);
        self::assertSame($before->personId->value, $root->person());
        self::assertSame($before->entryTermId->value, $root->entryTerm());
        self::assertSame($credits, $f->sql->rows("sis_academic_credits"));
        self::assertSame($money, $f->financialEvents());
        self::assertSame($catalogs, $f->sql->rows("sis_student_catalogs"));
        self::assertSame($programs, $f->sql->rows("sis_student_programs"));
    }
    public function testRetryAfterALaterSelectionAndClosureReturnsTheOriginalResultWithoutSelectingAgain(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        $preview = $this->review($f, $p);
        $receipt = $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]);
        $this->select($f, $initial);
        $status = $this->records($f)->programs($p->studentId)["items"];
        $row = array_values(array_filter($status, fn(array $r): bool => $r["id"] === $p->id->value))[0];
        $close = $f
            ->programStatusService()
            ->review($p->studentId, $p->id, false, $row["revision"], "Fictional closure.");
        $f->programStatusService()->commit($p->studentId, $p->id, $close["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame(
            $receipt,
            $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]),
        );
        self::assertSame(
            $initial->programId->value,
            $f->admissions->student($p->studentId)->programId->value,
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
    }
    public function testChangingAwayAndBackInvalidatesAnOlderReviewEvenWhenTheStudentRevisionMatches(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        $stale = $this->review($f, $p);
        $this->select($f, $p);
        $this->select($f, $initial);
        $this->expectException(ConcurrentModificationException::class);
        $f->primaryProgramService()->commit($p->studentId, $p->id, $stale["review_token"]);
    }
    public function testAClosedAndReopenedTargetInvalidatesTheEarlierPrimaryReview(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $stale = $this->review($f, $p);
        foreach ([false, true] as $active) {
            $row = array_values(
                array_filter(
                    $this->records($f)->programs($p->studentId)["items"],
                    fn(array $r): bool => $r["id"] === $p->id->value,
                ),
            )[0];
            $change = $f
                ->programStatusService()
                ->review($p->studentId, $p->id, $active, $row["revision"], "Fictional status decision.");
            $f->programStatusService()->commit($p->studentId, $p->id, $change["review_token"]);
        }
        $this->expectException(ConcurrentModificationException::class);
        $f->primaryProgramService()->commit($p->studentId, $p->id, $stale["review_token"]);
    }
    public function testHistoryRetainsLabelsWhenTheCurrentProgramIsRenamed(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $receipt = $this->select($f, $p);
        $f->sql->update(
            "sis_programs",
            ["code" => "RENAMED", "title" => "Renamed program"],
            ["id" => $p->programId->value],
        );
        $page = $f->primaryProgramService()->history($p->studentId);
        self::assertSame($receipt["selection"], $page["current_selection"]);
        self::assertSame("Fictional arts program", $page["items"][1]["selection"]["program_title"]);
        self::assertSame(
            "Renamed program",
            $this->records($f)->view($p->studentId)["primary_program"]["title"],
        );
    }
    public function testChangedReviewLabelsAreNotSilentlyAccepted(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $preview = $this->review($f, $p);
        $f->sql->update("sis_programs", ["title" => "Changed since review"], ["id" => $p->programId->value]);
        $this->expectException(ConcurrentModificationException::class);
        $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]);
    }
    public function testAuditFailureRollsBackBothEventsReceiptsCheckpointAndThePointer(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $before = $f->admissions->student($p->studentId)->programId->value;
        $preview = $this->review($f, $p);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        try {
            $f->primaryProgramService($audit)->commit($p->studentId, $p->id, $preview["review_token"]);
            self::fail("An unaudited primary change committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_history_operations"));
            self::assertSame([], $f->sql->rows("sis_primary_program_streams"));
            self::assertSame($before, $f->admissions->student($p->studentId)->programId->value);
        }
    }
    public function testOwnerAndStudentReadPermissionDoNotGrantSelectionOrHistoryAuthority(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $preview = $this->review($f, $p);
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $attempts = [
            fn() => $f->primaryProgramService()->history($p->studentId),
            fn() => $this->review($f, $p),
            fn() => $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Owner/read access granted primary management.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        self::assertNotEmpty($this->records($f)->programs($p->studentId)["items"]);
    }
    public function testTokensAreBoundToActorStudentAssignmentAndPurpose(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $other = $this->pursuit($f);
        $preview = $this->review($f, $p);
        $actor = $f->access->actor;
        $attempts = [
            [$other, $actor, $preview["review_token"]],
            [$p, $f->user(), $preview["review_token"]],
            [$p, $actor, $preview["review_token"] . "x"],
            [$p, $actor, $f->dropCipher->seal('{"purpose":"other"}')],
        ];
        foreach ($attempts as [$target, $who, $token]) {
            $f->access->actor = $who;
            try {
                $f->primaryProgramService()->commit($target->studentId, $target->id, $token);
                self::fail("An invalid token was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testForeignAndClosedAssignmentsCannotBeSelected(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $other = $this->pursuit($f);
        try {
            $f->primaryProgramService()->review(
                $p->studentId,
                $other->id,
                $this->records($f)->view($p->studentId)["revision"],
                "Fictional reason",
            );
            self::fail("A foreign assignment was accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $f->sql->update("sis_student_programs", ["status" => "closed"], ["id" => $p->id->value]);
        $this->expectException(ValidationException::class);
        $this->review($f, $p);
    }
    public function testReasonNoOpAndExpiredReviewsAreRejected(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        $revision = $this->records($f)->view($p->studentId)["revision"];
        $attempts = [
            fn() => $f->primaryProgramService()->review($p->studentId, $p->id, $revision, ""),
            fn() => $f->primaryProgramService()->review($p->studentId, $p->id, $revision, "Reason\0"),
            fn() => $this->review($f, $initial),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("An invalid selection was reviewed.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        $preview = $this->review($f, $p);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]);
    }
    public function testCurrentPermissionIsRecheckedAfterWaitingForTheStudentLock(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $revision = $this->records($f)->view($p->studentId)["revision"];
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $repo = $this->createMock(StudentRecordRepository::class);
        $repo
            ->expects(self::once())
            ->method("lock")
            ->willReturnCallback(function () use ($f): void {
                $f->access->grants = [];
            });
        $repo->expects(self::never())->method("assignment");
        $service = new StudentPrimaryProgramService(
            $repo,
            $f->admissions,
            $f->primaryProgramLedger(),
            $f->programStatusLedger(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $this->expectException(AccessDeniedException::class);
        $service->review($p->studentId, $p->id, $revision, "Fictional reason");
    }
    public function testConflictingPrimaryPointerBlocksRecordProgramClosureRegistrationAndBilling(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $original = $this->initial($f, $p);
        $this->select($f, $p);
        $f->sql->update(
            "sis_students",
            ["primary_program_id" => $original->programId->value],
            ["id" => $p->studentId->value],
        );
        $section = $f->section(10, 1000);
        $db = $f->connection->queryBuilder();
        $billing = new \Infrastructure\Persistence\EventSourcedRegistrationBilling(
            $f->accounts,
            $f->sql,
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $f->sql,
                EventStoreFixture::framework($db),
                $f->transactions,
            ),
            $f->admissions,
            $f->primaryProgramLedger(),
        );
        $attempts = [
            fn() => $this->records($f)->view($p->studentId),
            fn() => $this->records($f)->programs($p->studentId),
            fn() => $f->programStatusService()->history($p->studentId, $p->id),
            fn() => $f->service->register($p->studentId, $section->id, true),
            fn() => $billing->charge(
                $p->studentId,
                $f->term,
                EntityId::generate(),
                new \Domain\Finance\ValueObject\Money(1000, "USD"),
                $f->access->actor,
            ),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Conflicting primary records were used.");
            } catch (CorruptHistoryException) {
                self::assertSame([], $f->sql->rows("sis_student_accounts"));
            }
        }
    }
    public function testMissingCheckpointIsNotRecreatedByARead(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $this->select($f, $p);
        $events = $f->sql->rows("sis_history_events");
        $f->sql->execute("DELETE FROM sis_primary_program_streams WHERE id = ?", [$p->studentId->value]);
        try {
            $f->primaryProgramService()->history($p->studentId);
            self::fail("A missing checkpoint was accepted.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $f->sql->rows("sis_primary_program_streams"));
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }
    public function testFrozenHistoryPagesAndProtectedReasonsRemainPrivate(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        for ($i = 0; $i < 12; ++$i) {
            $this->select($f, $i % 2 === 0 ? $p : $initial);
        }
        $page = $f->primaryProgramService()->history($p->studentId);
        self::assertCount(10, $page["items"]);
        $this->select($f, $p);
        $last = $f->primaryProgramService()->history($p->studentId, $page["next_cursor"]);
        self::assertCount(3, $last["items"]);
        self::assertNull($last["next_cursor"]);
        self::assertStringNotContainsString("reason", json_encode($last));
        self::assertStringNotContainsString("actor_id", json_encode($last));
        self::assertStringNotContainsString(
            "Fictional protected primary selection reason.",
            json_encode($f->sql->rows("sis_history_events")),
        );
        self::assertStringNotContainsString(
            "Fictional protected primary selection reason.",
            json_encode($f->sql->rows("sis_audit")),
        );
    }
    public function testAnAmbiguousExistingPrimaryPursuitIsNotGuessedOrBackfilled(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $initial = $this->initial($f, $p);
        $term = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => "27/FA",
            "starts_on" => "2027-08-01",
            "ends_on" => "2027-12-15",
            "add_drop_ends_on" => "2027-08-15",
        ]);
        new SqlStudentRecordRepository($f->sql)->addAssignment(
            new StudentProgram(EntityId::generate(), $p->studentId, $initial->programId, $term),
        );
        try {
            $f->primaryProgramService()->history($p->studentId);
            self::fail("An ambiguous primary assignment was guessed.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_primary_program_streams"));
        }
    }
    public function testAReviewCrossingMidnightNeedsReviewAgainEvenBeforeItsTimeout(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $f->clock->time = $f->clock->time->setTime(23, 55);
        $preview = $this->review($f, $p);
        $f->clock->time = $f->clock->time->modify("+10 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->primaryProgramService()->commit($p->studentId, $p->id, $preview["review_token"]);
    }
    public function testLaterBillingUsesTheVerifiedPrimaryProgramAndKeepsEarlierCharges(): void
    {
        $f = new SisFixture();
        $p = $this->pursuit($f);
        $original = $this->initial($f, $p);
        $db = $f->connection->queryBuilder();
        $rules = new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
            $f->sql,
            EventStoreFixture::framework($db),
            $f->transactions,
        );
        $billing = new \Infrastructure\Persistence\EventSourcedRegistrationBilling(
            $f->accounts,
            $f->sql,
            $rules,
            $f->admissions,
            $f->primaryProgramLedger(),
        );
        $service = new \Domain\Finance\Service\TermBillingService(
            $rules,
            $billing,
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $service->define(
            $f->term,
            $original->programId,
            "Original program fee",
            "fee",
            new \Domain\Finance\ValueObject\Money(1000, "USD"),
        );
        $service->generate($f->term, [$p->studentId]);
        $first = $f->sql->one("sis_student_accounts", [
            "student_id" => $p->studentId->value,
            "term_id" => $f->term->value,
        ]);
        $oldEvents = $f->financialEvents();
        $this->select($f, $p);
        self::assertSame($oldEvents, $f->financialEvents());
        $term = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => "27/FA",
            "starts_on" => "2027-08-01",
            "ends_on" => "2027-12-15",
            "add_drop_ends_on" => "2027-08-15",
        ]);
        $service->define(
            $term,
            $original->programId,
            "Earlier program fee",
            "fee",
            new \Domain\Finance\ValueObject\Money(3000, "USD"),
        );
        $service->define(
            $term,
            $p->programId,
            "Current program fee",
            "fee",
            new \Domain\Finance\ValueObject\Money(2000, "USD"),
        );
        $service->generate($term, [$p->studentId]);
        $next = $f->sql->one("sis_student_accounts", [
            "student_id" => $p->studentId->value,
            "term_id" => $term->value,
        ]);
        self::assertSame(
            1000,
            $f->accounts->get(new \Domain\Finance\ValueObject\AccountId($first["id"]))->balance()->minor,
        );
        self::assertSame(
            2000,
            $f->accounts->get(new \Domain\Finance\ValueObject\AccountId($next["id"]))->balance()->minor,
        );
    }
}
