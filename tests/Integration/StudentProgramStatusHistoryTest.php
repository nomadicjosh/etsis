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
use Domain\Student\Service\StudentProgramStatusService;
use Domain\Student\Service\StudentRecordService;
use Domain\Student\StudentProgram;
use Domain\Student\StudentProgramStatus;
use Domain\Student\ValueObject\StudentProgramStatusId;
use Domain\Student\ValueObject\StudentProgramStatusSchema;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class StudentProgramStatusHistoryTest extends TestCase
{
    private function assignment(
        SisFixture $f,
        ?EntityId $student = null,
        ?EntityId $program = null,
    ): StudentProgram {
        $student ??= $f->student()->id;
        if ($program === null) {
            $program = EntityId::generate();
            $f->sql->insert("sis_programs", [
                "id" => $program->value,
                "code" => "FIX." . substr($program->value, -8),
                "title" => "Fictional program",
            ]);
        }
        $p = new StudentProgram(EntityId::generate(), $student, $program, $f->term);
        new SqlStudentRecordRepository($f->sql)->addAssignment($p);
        return $p;
    }
    private function current(SisFixture $f, StudentProgram $p): StudentProgram
    {
        return new SqlStudentRecordRepository($f->sql)->assignment($p->id);
    }
    private function revision(StudentProgram $p): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $p->id->value,
                    $p->studentId->value,
                    $p->programId->value,
                    $p->entryTermId->value,
                    $p->status,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function review(SisFixture $f, StudentProgram $p, bool $active = false): array
    {
        return $f
            ->programStatusService()
            ->review(
                $p->studentId,
                $p->id,
                $active,
                $this->revision($this->current($f, $p)),
                "Fictional protected program reason.",
            );
    }
    private function decide(SisFixture $f, StudentProgram $p, bool $active): array
    {
        return $f
            ->programStatusService()
            ->commit($p->studentId, $p->id, $this->review($f, $p, $active)["review_token"]);
    }
    private function records(SisFixture $f): StudentRecordService
    {
        return new StudentRecordService(
            new SqlStudentRecordRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->catalogEnrollment(),
            $f->activityLedger(),
            $f->programStatusLedger(),
        );
    }
    public function testReviewAndHistoryDoNotWriteEventsAndConfirmationRetainsAnExplicitObservation(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        self::assertFalse($f->programStatusService()->history($p->studentId, $p->id)["history_recorded"]);
        $review = $this->review($f, $p);
        self::assertSame("active", $review["before"]);
        self::assertSame("closed", $review["after"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
        $result = $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]);
        self::assertSame("closed", $result["status"]);
        self::assertSame(2, $result["version"]);
        $page = $f->programStatusService()->history($p->studentId, $p->id);
        self::assertSame(["observation", "decision"], array_column($page["items"], "kind"));
        self::assertSame(["active", "closed"], array_column($page["items"], "status"));
        self::assertTrue($page["earlier_history_unknown"]);
        self::assertSame($f->clock->now()->format(DATE_ATOM), $page["items"][1]["effective_at"]);
    }
    public function testReplayRebuildsStatusWithoutChangingEntryTermIdentityCreditsOrFinances(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $f->service->register($p->studentId, $f->section(10, 1500)->id);
        $credits = $f->sql->rows("sis_academic_credits");
        $money = $f->financialEvents();
        $catalogs = $f->sql->rows("sis_student_catalogs");
        $this->decide($f, $p, false);
        $this->decide($f, $p, true);
        $root = StudentProgramStatus::root(new StudentProgramStatusId($p->id->value));
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new StudentProgramStatusId($p->id->value),
        );
        foreach ($events as $e) {
            $root->acceptHistorical($e);
        }
        self::assertEquals(StudentProgramStatusSchema::facts($p), $root->facts());
        self::assertSame(3, $root->playhead());
        self::assertSame($credits, $f->sql->rows("sis_academic_credits"));
        self::assertSame($money, $f->financialEvents());
        self::assertSame($catalogs, $f->sql->rows("sis_student_catalogs"));
    }
    public function testRetryAfterReopeningReturnsTheOriginalClosureWithoutClosingAgain(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $review = $this->review($f, $p);
        $first = $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]);
        $this->decide($f, $p, true);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame(
            $first,
            $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]),
        );
        self::assertSame("active", $this->current($f, $p)->status);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
    }
    public function testAnActiveClosedActiveCycleInvalidatesAnOlderReview(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $stale = $this->review($f, $p);
        $this->decide($f, $p, false);
        $this->decide($f, $p, true);
        $this->expectException(ConcurrentModificationException::class);
        $f->programStatusService()->commit($p->studentId, $p->id, $stale["review_token"]);
    }
    public function testChangingPrimaryProgramAfterReviewPreventsItsClosure(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $review = $this->review($f, $p);
        $records = $this->records($f);
        $records->primary($p->studentId, $p->id, $records->view($p->studentId)["revision"]);
        $this->expectException(ConcurrentModificationException::class);
        $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]);
    }
    public function testPrimaryAssignmentsCannotBeClosedAndDuplicateActiveProgramsCannotBeReopened(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $repo = new SqlStudentRecordRepository($f->sql);
        $primary = $repo->assignments($student->id, null, 1)[0];
        $p = $this->assignment($f, $student->id);
        $this->decide($f, $p, false);
        // Explicit fixture of a later pursuit of the same program in a different entry term.
        $term = EntityId::generate();
        $f->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => "27/FA",
            "starts_on" => "2027-08-01",
            "ends_on" => "2027-12-15",
            "add_drop_ends_on" => "2027-08-15",
        ]);
        $repo->addAssignment(new StudentProgram(EntityId::generate(), $student->id, $p->programId, $term));
        $attempts = [
            fn() => $this->review($f, $repo->assignment(new EntityId($primary["id"]))),
            fn() => $this->review($f, $p, true),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("An invalid program status was reviewed.");
            } catch (ValidationException) {
                self::assertSame("closed", $this->current($f, $p)->status);
            }
        }
    }
    public function testAuditFailureRollsBackEventsReceiptsCheckpointAndStatus(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $review = $this->review($f, $p);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        try {
            $f->programStatusService($audit)->commit($p->studentId, $p->id, $review["review_token"]);
            self::fail("An unaudited program change committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_history_operations"));
            self::assertSame([], $f->sql->rows("sis_program_status_streams"));
            self::assertSame("active", $this->current($f, $p)->status);
        }
    }
    public function testOwnerAndStudentReadPermissionDoNotGrantStatusHistoryOrWrites(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $review = $this->review($f, $p);
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $attempts = [
            fn() => $this->review($f, $p),
            fn() => $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]),
            fn() => $f->programStatusService()->history($p->studentId, $p->id),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Ownership/read access granted registrar authority.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        self::assertNotEmpty($this->records($f)->programs($p->studentId)["items"]);
    }
    public function testAssignmentOwnershipAndTokenActorStudentAndPurposeAreChecked(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $other = $this->assignment($f);
        $review = $this->review($f, $p);
        try {
            $f->programStatusService()->history($other->studentId, $p->id);
            self::fail("A foreign program history was exposed.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $owner = $f->access->actor;
        $attempts = [
            [$other, $owner, $review["review_token"]],
            [$p, $f->user(), $review["review_token"]],
            [$p, $owner, $review["review_token"] . "x"],
            [$p, $owner, $f->dropCipher->seal('{"purpose":"other"}')],
        ];
        foreach ($attempts as [$target, $actor, $token]) {
            $f->access->actor = $actor;
            try {
                $f->programStatusService()->commit($target->studentId, $target->id, $token);
                self::fail("An invalid token was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testPermissionIsRecheckedAfterWaitingForTheStudentLock(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
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
        $service = new StudentProgramStatusService(
            $repo,
            $f->admissions,
            $f->programStatusLedger(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $this->expectException(AccessDeniedException::class);
        $service->review($p->studentId, $p->id, false, $this->revision($p), "Fictional reason");
    }
    public function testMissingReasonNoOpAndExpiredReviewAreRejected(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $attempts = [
            fn() => $f->programStatusService()->review($p->studentId, $p->id, false, $this->revision($p), ""),
            fn() => $f
                ->programStatusService()
                ->review($p->studentId, $p->id, false, $this->revision($p), "Reason\0"),
            fn() => $this->review($f, $p, true),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("An invalid decision was reviewed.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        $review = $this->review($f, $p);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->programStatusService()->commit($p->studentId, $p->id, $review["review_token"]);
    }
    public function testProjectionMismatchStopsHistoryProgramListAndPrimarySelection(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $this->decide($f, $p, false);
        $f->sql->update("sis_student_programs", ["status" => "active"], ["id" => $p->id->value]);
        $records = $this->records($f);
        $attempts = [
            fn() => $f->programStatusService()->history($p->studentId, $p->id),
            fn() => $records->programs($p->studentId),
            fn() => $records->primary($p->studentId, $p->id, $records->view($p->studentId)["revision"]),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Conflicting program records were used.");
            } catch (CorruptHistoryException) {
                self::assertCount(2, $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testCheckpointMismatchDoesNotSilentlyRepairTheRecords(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $this->decide($f, $p, false);
        $events = $f->sql->rows("sis_history_events");
        $f->sql->update("sis_program_status_streams", ["stream_version" => 99], ["id" => $p->id->value]);
        try {
            $f->programStatusService()->history($p->studentId, $p->id);
            self::fail("An incorrect history checkpoint was accepted.");
        } catch (CorruptHistoryException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame(
                99,
                $f->sql->one("sis_program_status_streams", ["id" => $p->id->value])["stream_version"],
            );
        }
    }
    public function testDuplicateChecksVerifyTheOtherPursuitsRecordedStatus(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $this->decide($f, $p, false);
        $this->decide($f, $p, true);
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '27/FA', 'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-15', 'add_drop_ends_on' => '2027-08-15']);
        $other = new StudentProgram(EntityId::generate(), $p->studentId, $p->programId, $term, 'closed');
        new SqlStudentRecordRepository($f->sql)->addAssignment($other);
        // An unauthorized SQL edit must not make the first active pursuit disappear from the check.
        $f->sql->update('sis_student_programs', ['status' => 'closed'], ['id' => $p->id->value]);
        try {
            $this->review($f, $other, true);
            self::fail('A conflicting status allowed a second active pursuit.');
        } catch (CorruptHistoryException) {
            self::assertCount(3, $f->sql->rows('sis_history_events'));
            self::assertSame('closed', $this->current($f, $other)->status);
        }
    }
    public function testReopeningRechecksNewActiveAssignmentsCreatedSinceReview(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        $this->decide($f, $p, false);
        $review = $this->review($f, $p, true);
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '27/FA', 'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-15', 'add_drop_ends_on' => '2027-08-15']);
        $this->records($f)->assign($p->studentId, $p->programId, $term);
        $before = $f->sql->rows('sis_history_events');
        try {
            $f->programStatusService()->commit($p->studentId, $p->id, $review['review_token']);
            self::fail('The duplicate-program rule was not checked again.');
        } catch (ValidationException) {
            self::assertSame('closed', $this->current($f, $p)->status);
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }
    public function testFrozenHistoryPagesExcludeLaterDecisionsAndProtectedReasons(): void
    {
        $f = new SisFixture();
        $p = $this->assignment($f);
        for ($i = 0; $i < 12; ++$i) {
            $this->decide($f, $p, $i % 2 === 1);
        }
        $page = $f->programStatusService()->history($p->studentId, $p->id);
        self::assertCount(10, $page["items"]);
        $this->decide($f, $p, false);
        $last = $f->programStatusService()->history($p->studentId, $p->id, $page["next_cursor"]);
        self::assertCount(3, $last["items"]);
        self::assertNull($last["next_cursor"]);
        self::assertStringNotContainsString("reason", json_encode($last));
        self::assertStringNotContainsString("actor_id", json_encode($last));
        self::assertStringNotContainsString(
            "Fictional protected program reason.",
            json_encode($f->sql->rows("sis_history_events")),
        );
        self::assertStringNotContainsString(
            "Fictional protected program reason.",
            json_encode($f->sql->rows("sis_audit")),
        );
    }
}
