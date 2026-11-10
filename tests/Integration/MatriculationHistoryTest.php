<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Admissions\AdmissionApplication;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentActivity;
use Domain\Student\ValueObject\StudentActivityId;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class MatriculationHistoryTest extends TestCase
{
    private function application(SisFixture $f): AdmissionApplication
    {
        $a = $f->admissionsService();
        $person = $a->createPerson($f->user(), "Fictional", "Prospect");
        $application = $f->legacyApplication($person->id, $f->program, $f->term);
        $f->legacyAdmissionDecision($application->id, true);
        return $f->admissions->application($application->id);
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
    private function revision(SisFixture $f, EntityId $id): string
    {
        $s = $f->admissions->student($id);
        return hash(
            "sha256",
            json_encode(
                [$id->value, $s->personId->value, $s->programId->value, $s->entryTermId->value, $s->active],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    public function testPreviewIsWriteFreeAndConfirmationStartsFourConsistentHistories(): void
    {
        $f = new SisFixture();
        $year = $this->year($f);
        $a = $this->application($f);
        $service = $f->matriculationService();
        $review = $service->review($a->id, "Fictional protected matriculation reason");
        self::assertSame($year->value, $review["source"]["cohort"]["year_id"]);
        self::assertNull($review["source"]["cohort"]["catalog_id"]);
        $tables = [
            "sis_students",
            "sis_student_programs",
            "sis_program_catalogs",
            "sis_student_catalogs",
            "sis_history_events",
        ];
        foreach ($tables as $table) {
            self::assertSame([], $f->sql->rows($table));
        }
        $r = $service->commit($a->id, $review["review_token"]);
        $student = new EntityId($r["student_id"]);
        self::assertSame("matriculated", $f->admissions->application($a->id)->status->value);
        self::assertCount(4, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_student_catalogs"));
        self::assertSame(1, $f->activityLedger()->verify($f->admissions->student($student)));
        self::assertSame(1, $f->primaryProgramLedger()->verify($f->admissions->student($student)));
        $timeline = $service->history($a->id);
        self::assertTrue($timeline["history_recorded"]);
        self::assertTrue($timeline["acceptance_history_unknown"]);
        self::assertFalse($timeline["earlier_history_unknown"]);
        self::assertSame($year->value, $timeline["source"]["cohort"]["year_id"]);
        self::assertSame($r, $timeline["matriculation"]);
        self::assertSame(
            ["initial"],
            array_column($f->activityService()->history($student)["items"], "kind"),
        );
        self::assertSame(
            ["initial"],
            array_column($f->primaryProgramService()->history($student)["items"], "kind"),
        );
        self::assertFalse($f->activityService()->history($student)["earlier_history_unknown"]);
        self::assertFalse($f->primaryProgramService()->history($student)["earlier_history_unknown"]);
        $serialized = json_encode([$timeline, $f->sql->rows("sis_history_events")], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional protected matriculation reason", $serialized);
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new \Domain\Admissions\ValueObject\MatriculationId($a->id->value),
        );
        self::assertCount(1, iterator_to_array($events));
    }
    public function testAnUnlinkedTermIsExplicitAndDoesNotInventACohort(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        self::assertNull($review["source"]["cohort"]);
        $s->commit($a->id, $review["review_token"]);
        self::assertNull($s->history($a->id)["source"]["cohort"]);
        self::assertSame([], $f->sql->rows("sis_student_catalogs"));
    }
    public function testOlderMatriculationRemainsUnknownWithoutWritingCreationEvents(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->legacyStudent($a->id);
        self::assertFalse($f->matriculationService()->history($a->id)["history_recorded"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
        $this->expectException(ValidationException::class);
        $f->matriculationService()->review($a->id, "Do not fabricate earlier history");
    }
    public function testExactRetryAfterAnotherPrimaryProgramClosureAndActivityDecisionPreservesLaterFacts(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional initial reason");
        $r = $s->commit($a->id, $review["review_token"]);
        $student = new EntityId($r["student_id"]);
        $program = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "BA.ART",
            "title" => "Fictional arts",
        ]);
        $additional = $f->assignProgram($student, $program, $f->term);
        $primary = $f
            ->primaryProgramService()
            ->review($student, $additional, $this->revision($f, $student), "Fictional primary decision");
        $f->primaryProgramService()->commit($student, $additional, $primary["review_token"]);
        $p = new SqlStudentRecordRepository($f->sql)->assignment(new EntityId($r["assignment_id"]));
        $revision = hash(
            "sha256",
            json_encode(
                [$p->id->value, $student->value, $p->programId->value, $p->entryTermId->value, $p->status],
                JSON_THROW_ON_ERROR,
            ),
        );
        $close = $f->programStatusService()->review($student, $p->id, false, $revision, "Fictional closure");
        $f->programStatusService()->commit($student, $p->id, $close["review_token"]);
        $activity = $f
            ->activityService()
            ->review($student, false, "2026-08-10", "Fictional inactive", $this->revision($f, $student));
        $f->activityService()->commit($student, $activity["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($r, $s->commit($a->id, $review["review_token"]));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame($program->value, $f->admissions->student($student)->programId->value);
        self::assertFalse($f->admissions->student($student)->active);
        self::assertSame("closed", new SqlStudentRecordRepository($f->sql)->assignment($p->id)->status);
        self::assertSame($r, $s->history($a->id)["matriculation"]);
    }
    public function testAnotherReviewCannotCreateAnotherStudentForTheSameApplication(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $first = $s->review($a->id, "First fictional review");
        $second = $s->review($a->id, "Second fictional review");
        $s->commit($a->id, $first["review_token"]);
        try {
            $s->commit($a->id, $second["review_token"]);
            self::fail("Duplicate matriculation accepted.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows("sis_students"));
            self::assertCount(4, $f->sql->rows("sis_history_events"));
        }
    }
    public function testDifferentAcceptedApplicationsForOnePersonCannotCreateDuplicateStudents(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $other = $f->legacyApplication($a->personId, $a->programId, $a->entryTermId);
        $f->legacyAdmissionDecision($other->id, true);
        $first = $s->review($a->id, "First application");
        $second = $s->review($other->id, "Other application");
        $s->commit($a->id, $first["review_token"]);
        try {
            $s->commit($other->id, $second["review_token"]);
            self::fail("Duplicate student accepted.");
        } catch (ValidationException) {
            self::assertSame("accepted", $f->admissions->application($other->id)->status->value);
            self::assertCount(1, $f->sql->rows("sis_students"));
            self::assertCount(4, $f->sql->rows("sis_history_events"));
        }
    }
    public function testChangedProgramLabelsRequireANewReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        $f->sql->update("sis_programs", ["title" => "Changed fictional title"], ["id" => $f->program->value]);
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($a->id, $review["review_token"]);
    }
    public function testChangedPersonIdentityRequiresANewReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        $f->admissionsService()->updateInstitutionalId($a->personId, "TEST-UPDATED", 0);
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($a->id, $review["review_token"]);
    }
    public function testChangedCalendarRequiresANewReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        $this->year($f);
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($a->id, $review["review_token"]);
    }
    public function testReviewsExpireAndCannotCrossAnInstitutionalDate(): void
    {
        foreach (["+21 minutes", "+1 day"] as $interval) {
            $f = new SisFixture();
            $a = $this->application($f);
            $s = $f->matriculationService();
            $review = $s->review($a->id, "Fictional reason");
            $f->clock->time = $f->clock->time->modify($interval);
            try {
                $s->commit($a->id, $review["review_token"]);
                self::fail("Expired review accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_students"));
            }
        }
    }
    public function testAdmissionStaffCanMatriculateWithoutRegistrarPermission(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:admissions:manage"];
        $student = $f->matriculate($a->id);
        self::assertTrue($student->active);
        self::assertCount(4, $f->sql->rows("sis_history_events"));
    }
    public function testOwnershipOrRegistrarPermissionDoesNotGrantMatriculationAuthority(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->staff = false;
        $f->access->actor = $f->admissions->person($a->personId)->ownerUserId;
        $f->access->grants = ["sis:student:read", "sis:registration:manage"];
        $this->expectException(AccessDeniedException::class);
        $f->matriculationService()->review($a->id, "Fictional forbidden action");
    }
    public function testTokenIsBoundToTheActorAndApplicationAndPermissionIsRechecked(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $other = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        foreach (["application", "actor", "permission", "damaged"] as $case) {
            $original = $f->access->actor;
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "permission") {
                $f->access->staff = false;
            }
            try {
                $s->commit(
                    $case === "application" ? $other->id : $a->id,
                    $case === "damaged" ? $review["review_token"] . "!" : $review["review_token"],
                );
                self::fail("Invalid authority accepted.");
            } catch (ValidationException | AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_students"));
            } finally {
                $f->access->actor = $original;
                $f->access->staff = true;
            }
        }
    }
    public function testAuditFailureRollsBackAllFourStreamsAndEveryProjectionAndAllowsRetry(): void
    {
        $f = new SisFixture();
        $this->year($f);
        $a = $this->application($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        $review = $f->matriculationService()->review($a->id, "Fictional reason");
        try {
            $f->matriculationService($audit)->commit($a->id, $review["review_token"]);
            self::fail("Partial matriculation committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure", $e->getMessage());
        }
        $tables = [
            "sis_students",
            "sis_student_programs",
            "sis_student_catalogs",
            "sis_program_catalogs",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_student_activity",
            "sis_primary_program_streams",
            "sis_program_status_streams",
        ];
        foreach ($tables as $table) {
            self::assertSame([], $f->sql->rows($table));
        }
        self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
        $f->matriculationService()->commit($a->id, $review["review_token"]);
        self::assertCount(4, $f->sql->rows("sis_history_events"));
    }
    public function testDamagedCurrentStudentProjectionBlocksHistoryAndRetry(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        $review = $s->review($a->id, "Fictional reason");
        $r = $s->commit($a->id, $review["review_token"]);
        $f->sql->update("sis_students", ["active" => 0], ["id" => $r["student_id"]]);
        $this->expectException(CorruptHistoryException::class);
        $s->commit($a->id, $review["review_token"]);
    }
    public function testARecordedInitialActivationControlsLaterBackdatedActivityAndReplaysBeforeEntryAsUnknown(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $student = $f->matriculate($a->id);
        $review = $f
            ->activityService()
            ->review(
                $student->id,
                false,
                "2026-08-01",
                "Fictional earlier decision",
                $this->revision($f, $student->id),
            );
        self::assertTrue($review["resulting_active"]);
        $f->activityService()->commit($student->id, $review["review_token"]);
        self::assertTrue($f->admissions->student($student->id)->active);
        $root = StudentActivity::root(new StudentActivityId($student->id->value));
        $root->effectiveThrough("2026-07-31");
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new StudentActivityId($student->id->value),
        );
        foreach ($events as $event) {
            $root->acceptHistorical($event);
        }
        self::assertNull($root->active());
        self::assertFalse($root->earlierHistoryUnknown());
    }
    public function testInvalidReasonsAreRejectedAndUnicodeReasonsFitTheReviewToken(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->matriculationService();
        foreach (["", "bad\x00reason", str_repeat("a", 10001), "\xFF"] as $reason) {
            try {
                $s->review($a->id, $reason);
                self::fail("Invalid reason accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        $review = $s->review($a->id, str_repeat("学", 10000));
        $s->commit($a->id, $review["review_token"]);
        self::assertCount(4, $f->sql->rows("sis_history_events"));
    }
    public function testPermissionRevokedWhileWaitingForLocksBlocksTheReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:admissions:manage"];
        $repo = $this->createMock(\Domain\Admissions\Repository\AdmissionsRepository::class);
        $repo->method("application")->willReturn($a);
        $repo
            ->expects(self::once())
            ->method("lockPerson")
            ->willReturnCallback(function () use ($f): void {
                $f->access->grants = [];
            });
        $repo->expects(self::once())->method("lockApplication");
        $repo->expects(self::never())->method("projectMatriculation");
        $this->expectException(AccessDeniedException::class);
        $f->matriculationService(repository: $repo)->review($a->id, "Fictional reason");
    }
    public function testHistoricalLabelsAndCohortRemainTheOriginalFactsAfterCatalogEdits(): void
    {
        $f = new SisFixture();
        $this->year($f);
        $a = $this->application($f);
        $f->matriculate($a->id);
        $before = $f->matriculationService()->history($a->id);
        $journal = $f->sql->rows("sis_history_events");
        $f->sql->update(
            "sis_programs",
            ["title" => "Later fictional program title"],
            ["id" => $a->programId->value],
        );
        $f->sql->update("sis_terms", ["code" => "LATER.LABEL"], ["id" => $a->entryTermId->value]);
        self::assertSame($before, $f->matriculationService()->history($a->id));
        self::assertSame($journal, $f->sql->rows("sis_history_events"));
    }
    public function testCreationSchemasRejectFalseActivationUnknownFieldsAndMalformedIdentifiers(): void
    {
        $application = EntityId::generate()->value;
        $student = EntityId::generate()->value;
        $activity = [
            "decision_id" => EntityId::generate()->value,
            "student_id" => $student,
            "person_id" => EntityId::generate()->value,
            "active" => true,
            "effective_on" => "2026-08-10",
            "application_id" => $application,
        ];
        $invalid = [
            $activity + ["extra" => "unexpected"],
            array_replace($activity, ["active" => false]),
            array_replace($activity, ["application_id" => "not-an-id"]),
            array_replace($activity, ["effective_on" => "2026-02-30"]),
        ];
        foreach ($invalid as $payload) {
            try {
                \Domain\Student\ValueObject\StudentActivitySchema::validate(
                    \Domain\Student\Event\StudentActivityWasStarted::class,
                    $payload,
                );
                self::fail("Invalid creation event accepted.");
            } catch (CorruptHistoryException | InvalidArgumentException $error) {
                self::assertNotSame("", $error->getMessage());
            }
        }
        $event = \Domain\Student\Event\StudentActivityWasStarted::occur(
            new StudentActivityId($student),
            $activity,
            ["__aggregate_type" => "student_activity"],
        )->withPlayhead(1);
        $root = StudentActivity::root(new StudentActivityId($student));
        $root->acceptHistorical($event);
        self::assertTrue($root->active());
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($event->withPlayhead(2));
    }
    public function testMatriculationSchemaAndRootRejectInvalidFactsAndASecondCreation(): void
    {
        $application = EntityId::generate()->value;
        $payload = [
            "decision_id" => EntityId::generate()->value,
            "application_id" => $application,
            "person_id" => EntityId::generate()->value,
            "program_id" => EntityId::generate()->value,
            "entry_term_id" => EntityId::generate()->value,
            "student_id" => EntityId::generate()->value,
            "assignment_id" => EntityId::generate()->value,
            "accepted_version" => 1,
        ];
        $invalid = [
            $payload + ["unknown" => true],
            array_replace($payload, ["accepted_version" => true]),
            array_replace($payload, ["accepted_version" => 0]),
            array_replace($payload, ["student_id" => "invalid"]),
        ];
        foreach ($invalid as $facts) {
            try {
                \Domain\Admissions\ValueObject\MatriculationSchema::validate(
                    \Domain\Admissions\Event\StudentWasMatriculated::class,
                    $facts,
                );
                self::fail("Invalid matriculation accepted.");
            } catch (CorruptHistoryException | InvalidArgumentException $error) {
                self::assertNotSame("", $error->getMessage());
            }
        }
        $id = new \Domain\Admissions\ValueObject\MatriculationId($application);
        $event = \Domain\Admissions\Event\StudentWasMatriculated::occur($id, $payload, [
            "__aggregate_type" => "matriculation",
        ])->withPlayhead(1);
        $root = \Domain\Admissions\Matriculation::root($id);
        $root->acceptHistorical($event);
        self::assertSame($payload, $root->facts());
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($event->withPlayhead(2));
    }
    public function testInitialPrimaryProgramMustUseTheInstitutionalEntryTerm(): void
    {
        $p = [
            "decision_id" => EntityId::generate()->value,
            "application_id" => EntityId::generate()->value,
            "student_id" => EntityId::generate()->value,
            "person_id" => EntityId::generate()->value,
            "institutional_entry_term_id" => EntityId::generate()->value,
            "selection" => [
                "assignment_id" => EntityId::generate()->value,
                "program_id" => EntityId::generate()->value,
                "entry_term_id" => EntityId::generate()->value,
                "program_code" => "BS.CS",
                "program_title" => "Fictional computing",
                "entry_term_code" => "26/FA",
            ],
        ];
        $this->expectException(CorruptHistoryException::class);
        \Domain\Student\ValueObject\PrimaryProgramSchema::validate(
            \Domain\Student\Event\PrimaryProgramWasEstablished::class,
            $p,
        );
    }
}
