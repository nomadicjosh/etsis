<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\CatalogEnrollment;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentProgramAssignmentService;
use Domain\Student\Student;
use Domain\Student\StudentProgram;
use Domain\Student\StudentProgramStatus;
use Domain\Student\ValueObject\StudentProgramStatusId;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class StudentProgramAssignmentTest extends TestCase
{
    private function program(SisFixture $f): EntityId
    {
        $id = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $id->value,
            "code" => "BA." . substr($id->value, -6),
            "title" => "Fictional arts",
        ]);
        return $id;
    }
    private function revision(SisFixture $f, EntityId $student): string
    {
        $s = $f->admissions->student($student);
        return hash(
            "sha256",
            json_encode(
                [
                    $s->id->value,
                    $s->personId->value,
                    $s->programId->value,
                    $s->entryTermId->value,
                    $s->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function review(SisFixture $f, EntityId $student, EntityId $program): array
    {
        return $f
            ->programAssignmentService()
            ->review(
                $student,
                $program,
                $f->term,
                $this->revision($f, $student),
                "Fictional protected assignment reason.",
            );
    }
    private function year(SisFixture $f): EntityId
    {
        $id = EntityId::generate();
        $f->sql->insert("sis_catalog_years", [
            "id" => $id->value,
            "code" => "2026-2027",
            "title" => "Derived cohort",
            "starts_on" => "2026-08-01",
            "ends_on" => "2027-07-31",
            "published" => 1,
        ]);
        $f->sql->insert("sis_academic_years", [
            "id" => $id->value,
            "start_year" => 2026,
            "end_year" => 2027,
            "code" => "2026-2027",
            "starts_on" => "2026-08-01",
            "ends_on" => "2027-07-31",
            "legacy" => 0,
        ]);
        $f->sql->insert("sis_term_calendar", [
            "id" => $f->term->value,
            "academic_year_id" => $id->value,
            "kind" => "standard",
            "name" => "Fall 2026",
            "version" => 0,
        ]);
        return $id;
    }
    private function close(SisFixture $f, EntityId $student, EntityId $assignment): void
    {
        $p = new SqlStudentRecordRepository($f->sql)->assignment($assignment);
        $revision = hash(
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
        $preview = $f
            ->programStatusService()
            ->review($student, $assignment, false, $revision, "Fictional program closure.");
        $f->programStatusService()->commit($student, $assignment, $preview["review_token"]);
    }
    public function testReviewDoesNotCreateAssignmentCatalogBindingOrEvents(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $year = $this->year($f);
        $before = $f->sql->rows("sis_student_programs");
        $preview = $this->review($f, $student->id, $program);
        self::assertSame($year->value, $preview["source"]["cohort"]["year_id"]);
        self::assertNull($preview["source"]["cohort"]["catalog_id"]);
        self::assertSame(1, $preview["source"]["cohort"]["revision"]);
        self::assertSame($before, $f->sql->rows("sis_student_programs"));
        self::assertSame([], $f->sql->rows("sis_program_catalogs"));
        self::assertSame([], $f->sql->rows("sis_student_catalogs"));
        self::assertSame([], $f->sql->rows("sis_student_catalog_changes"));
        self::assertSame([], $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_program_status_streams"));
    }
    public function testConfirmationCreatesOneReplayableAssignmentAndTheReviewedCohort(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $this->year($f);
        $preview = $this->review($f, $student->id, $program);
        $receipt = $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
        $id = new EntityId($receipt["assignment_id"]);
        self::assertSame(1, $receipt["version"]);
        $page = $f->programStatusService()->history($student->id, $id);
        self::assertFalse($page["earlier_history_unknown"]);
        self::assertSame("assignment", $page["items"][0]["kind"]);
        self::assertSame("Fictional arts", $page["assignment_source"]["program_title"]);
        $binding = new SqlCatalogYearRepository($f->sql)->binding($id);
        self::assertSame($binding["catalog_id"], $page["assignment_source"]["cohort"]["catalog_id"]);
        self::assertSame("2026-2027", $page["assignment_source"]["cohort"]["year_code"]);
        $root = StudentProgramStatus::root(new StudentProgramStatusId($id->value));
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new StudentProgramStatusId($id->value),
        );
        foreach ($events as $event) {
            $root->acceptHistorical($event);
        }
        self::assertSame($student->id->value, $root->facts()["student_id"]);
        self::assertSame($program->value, $root->facts()["program_id"]);
        self::assertSame("active", $root->facts()["status"]);
        self::assertSame($student->programId->value, $f->admissions->student($student->id)->programId->value);
    }
    public function testExistingPublishedCurriculumIsSelectedWithoutCreatingAnotherRevision(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $year = $this->year($f);
        $catalogs = new SqlCatalogYearRepository($f->sql);
        $catalog = EntityId::generate();
        $catalogs->addRelease($catalog, [
            "program_id" => $program->value,
            "year_id" => $year->value,
            "revision" => 4,
            "published" => 1,
            "is_current" => 1,
            "program_code" => "BA.ART",
            "program_title" => "Arts curriculum",
        ]);
        $preview = $this->review($f, $student->id, $program);
        self::assertSame($catalog->value, $preview["source"]["cohort"]["catalog_id"]);
        $receipt = $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
        self::assertCount(1, $f->sql->rows("sis_program_catalogs"));
        self::assertSame(
            $catalog->value,
            $catalogs->binding(new EntityId($receipt["assignment_id"]))["catalog_id"],
        );
    }
    public function testAnUnmappedTermIsExplicitlyUnlinkedAndDoesNotInventACohort(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $preview = $this->review($f, $student->id, $this->program($f));
        self::assertNull($preview["source"]["cohort"]);
        $receipt = $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
        $page = $f->programStatusService()->history($student->id, new EntityId($receipt["assignment_id"]));
        self::assertNull($page["assignment_source"]["cohort"]);
        self::assertSame([], $f->sql->rows("sis_program_catalogs"));
        self::assertSame([], $f->sql->rows("sis_student_catalogs"));
    }
    public function testRetryAfterClosingReturnsTheCreationReceiptWithoutReopeningOrDuplicating(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $preview = $this->review($f, $student->id, $this->program($f));
        $receipt = $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
        $id = new EntityId($receipt["assignment_id"]);
        $this->close($f, $student->id, $id);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame(
            $receipt,
            $f->programAssignmentService()->commit($student->id, $preview["review_token"]),
        );
        self::assertSame("closed", new SqlStudentRecordRepository($f->sql)->assignment($id)->status);
        self::assertCount(2, $f->sql->rows("sis_student_programs"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
        self::assertFalse($f->programStatusService()->history($student->id, $id)["earlier_history_unknown"]);
    }
    public function testTheNewAssignmentCanBeSelectedAsPrimaryAndKeepsItsCreationHistory(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $id = $f->assignProgram($student->id, $program, $f->term);
        $preview = $f
            ->primaryProgramService()
            ->review($student->id, $id, $this->revision($f, $student->id), "Fictional primary selection.");
        $f->primaryProgramService()->commit($student->id, $id, $preview["review_token"]);
        self::assertSame($program->value, $f->admissions->student($student->id)->programId->value);
        $history = $f->programStatusService()->history($student->id, $id);
        self::assertSame("assignment", $history["items"][0]["kind"]);
        self::assertFalse($history["earlier_history_unknown"]);
    }
    public function testTwoReviewsOfTheSameProgramCannotCreateDuplicateAssignments(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $one = $this->review($f, $student->id, $program);
        $two = $this->review($f, $student->id, $program);
        $f->programAssignmentService()->commit($student->id, $one["review_token"]);
        try {
            $f->programAssignmentService()->commit($student->id, $two["review_token"]);
            self::fail("A second active assignment was created.");
        } catch (ValidationException) {
            self::assertCount(2, $f->sql->rows("sis_student_programs"));
            self::assertCount(1, $f->sql->rows("sis_history_events"));
        }
    }
    public function testClosedAssignmentForTheSameEntryTermMustBeReopenedInsteadOfRecreated(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $id = $f->assignProgram($student->id, $program, $f->term);
        $this->close($f, $student->id, $id);
        $this->expectException(ValidationException::class);
        $this->review($f, $student->id, $program);
    }
    public function testChangedCalendarMembershipInvalidatesTheReviewedCohort(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $preview = $this->review($f, $student->id, $program);
        $this->year($f);
        $this->expectException(ConcurrentModificationException::class);
        $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
    }
    public function testPublishingADifferentCurriculumAfterReviewRequiresAnotherReview(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $year = $this->year($f);
        $preview = $this->review($f, $student->id, $program);
        new SqlCatalogYearRepository($f->sql)->addRelease(EntityId::generate(), [
            "program_id" => $program->value,
            "year_id" => $year->value,
            "revision" => 2,
            "published" => 1,
            "is_current" => 1,
            "program_code" => "BA.ART",
            "program_title" => "New curriculum",
        ]);
        $this->expectException(ConcurrentModificationException::class);
        $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
    }
    public function testAuditFailureRollsBackAssignmentBirthCohortBindingAndReceipts(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $this->year($f);
        $preview = $this->review($f, $student->id, $program);
        $before = $f->sql->rows("sis_student_programs");
        $auditBefore = $f->sql->rows("sis_audit");
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        try {
            $f->programAssignmentService($audit)->commit($student->id, $preview["review_token"]);
            self::fail("An unaudited assignment committed.");
        } catch (RuntimeException) {
            self::assertSame($before, $f->sql->rows("sis_student_programs"));
            self::assertSame($auditBefore, $f->sql->rows("sis_audit"));
            $rolledBackTables =
            [
                    "sis_history_events",
                    "sis_history_operations",
                    "sis_program_status_streams",
                    "sis_program_catalogs",
                    "sis_student_catalogs",
                    "sis_student_catalog_changes",
                ];
            foreach ($rolledBackTables as $table) {
                self::assertSame([], $f->sql->rows($table), $table);
            }
        }
    }
    public function testOwnersAndStudentReadStaffCannotCreateAssignments(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $preview = $this->review($f, $student->id, $program);
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        $attempts = [
            fn() => $this->review($f, $student->id, $program),
            fn() => $f->programAssignmentService()->commit($student->id, $preview["review_token"]),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Student access granted program assignment authority.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testTokenActorStudentPurposeAndTamperingAreChecked(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $other = $f->student();
        $preview = $this->review($f, $student->id, $this->program($f));
        $actor = $f->access->actor;
        $attempts = [
            [$other->id, $actor, $preview["review_token"]],
            [$student->id, $f->user(), $preview["review_token"]],
            [$student->id, $actor, $preview["review_token"] . "x"],
            [$student->id, $actor, $f->dropCipher->seal('{"purpose":"other"}')],
        ];
        foreach ($attempts as [$target, $who, $token]) {
            $f->access->actor = $who;
            try {
                $f->programAssignmentService()->commit($target, $token);
                self::fail("An invalid token was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testReasonAndExpiryAreEnforced(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        foreach (["", "Reason\0"] as $reason) {
            try {
                $f->programAssignmentService()->review(
                    $student->id,
                    $program,
                    $f->term,
                    $this->revision($f, $student->id),
                    $reason,
                );
                self::fail("An invalid reason was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        $preview = $this->review($f, $student->id, $program);
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
    }
    public function testHistoricalSourceLabelsAndReasonsAreProtected(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $preview = $this->review($f, $student->id, $program);
        $receipt = $f->programAssignmentService()->commit($student->id, $preview["review_token"]);
        $f->sql->update("sis_programs", ["title" => "Renamed today"], ["id" => $program->value]);
        $page = $f->programStatusService()->history($student->id, new EntityId($receipt["assignment_id"]));
        self::assertSame("Fictional arts", $page["assignment_source"]["program_title"]);
        self::assertStringNotContainsString("Fictional protected assignment reason.", json_encode($page));
        self::assertStringNotContainsString(
            "Fictional protected assignment reason.",
            json_encode($f->sql->rows("sis_history_events")),
        );
        self::assertStringNotContainsString(
            "Fictional protected assignment reason.",
            json_encode($f->sql->rows("sis_audit")),
        );
    }
    public function testPermissionIsRecheckedAfterWaitingForTheStudentLock(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $repo = $this->createMock(StudentRecordRepository::class);
        $repo
            ->expects(self::once())
            ->method("lock")
            ->willReturnCallback(function () use ($f): void {
                $f->access->grants = [];
            });
        $repo->expects(self::never())->method("addAssignment");
        $service = new StudentProgramAssignmentService(
            $repo,
            $f->admissions,
            $f->programStatusLedger(),
            $f->primaryProgramLedger(),
            $f->activityLedger(),
            new SqlCatalogYearRepository($f->sql),
            $f->programEntryContext(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $this->expectException(AccessDeniedException::class);
        $service->review(
            $student->id,
            $program,
            $f->term,
            $this->revision($f, $student->id),
            "Fictional assignment reason.",
        );
    }
    public function testAnInitializerThatDoesNotApplyTheReviewedCohortRollsBackTheAssignment(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $this->year($f);
        $preview = $this->review($f, $student->id, $program);
        $initializer = $this->createStub(CatalogEnrollment::class);
        $service = new StudentProgramAssignmentService(
            new SqlStudentRecordRepository($f->sql),
            $f->admissions,
            $f->programStatusLedger(),
            $f->primaryProgramLedger(),
            $f->activityLedger(),
            new SqlCatalogYearRepository($f->sql),
            $f->programEntryContext($initializer),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        try {
            $service->commit($student->id, $preview["review_token"]);
            self::fail("An assignment without the reviewed cohort was accepted.");
        } catch (CorruptHistoryException) {
            self::assertCount(1, $f->sql->rows("sis_student_programs"));
            self::assertSame([], $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_program_status_streams"));
        }
    }
    public function testCreationSchemaRejectsUnexpectedPrivateFieldsAndInvalidStartingStatus(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $program = $this->program($f);
        $id = $f->assignProgram($student->id, $program, $f->term);
        $p = new SqlStudentRecordRepository($f->sql)->assignment($id);
        $source = $f->programStatusService()->history($student->id, $id)["assignment_source"];
        $payload = [
            "decision_id" => $id->value,
            "assignment" => \Domain\Student\ValueObject\StudentProgramStatusSchema::facts($p),
            "source" => $source,
        ];
        $invalid = [
            array_replace($payload, ["source" => $source + ["private_note" => "Unregistered field"]]),
            array_replace($payload, [
                "assignment" => array_replace($payload["assignment"], ["status" => "closed"]),
            ]),
            array_replace($payload, ["decision_id" => EntityId::generate()->value]),
        ];
        foreach ($invalid as $case) {
            try {
                \Domain\Student\ValueObject\StudentProgramStatusSchema::validate(
                    \Domain\Student\Event\StudentProgramWasAssigned::class,
                    $case,
                );
                self::fail("An invalid assignment event was accepted.");
            } catch (CorruptHistoryException) {
                self::assertCount(1, $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testAMaximumLengthUnicodeReasonFitsTheProtectedReviewToken(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $preview = $f
            ->programAssignmentService()
            ->review(
                $student->id,
                $this->program($f),
                $f->term,
                $this->revision($f, $student->id),
                str_repeat("学", 10000),
            );
        self::assertLessThanOrEqual(100000, strlen($preview["review_token"]));
        self::assertSame(
            "active",
            $f->programAssignmentService()->commit($student->id, $preview["review_token"])["status"],
        );
    }
}
