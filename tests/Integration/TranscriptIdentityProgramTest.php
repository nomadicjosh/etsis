<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Person\Person;
use Domain\Person\Service\ProfileLedger;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentRecordService;
use Domain\Student\StudentProgram;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptFulfillmentService;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use ProgramPublicationFixture;
use TranscriptFixture;

final class TranscriptIdentityProgramTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
    }

    private function service(TranscriptFixture $t): TranscriptFulfillmentService
    {
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional identity and program");
        return $t->service($renderer);
    }

    private function prepare(TranscriptFulfillmentService $service, EntityId $request): EntityId
    {
        $review = $service->review($request);
        $id = new EntityId($review["operation_id"]);
        $service->prepare($request, $id, $review["revision"], true, $review["review_token"]);
        return $id;
    }

    private function rename(TranscriptFixture $t, EntityId $id, string $name): void
    {
        $f = $t->sis;
        $f->transactions->run(function () use ($f, $id, $name): void {
            $f->admissions->lockPerson($id);
            $before = $f->admissions->person($id);
            $after = new Person(
                $id,
                $before->ownerUserId,
                $name,
                $before->familyName,
                $before->institutionalId,
                $before->directoryRestricted,
                $before->identityVersion,
            );
            $f->profileLedger()->record(
                $before,
                $after,
                EntityId::generate(),
                $f->access->actor,
                ProfileLedger::intent([$name, "Fictional current name correction"]),
            );
            new SqlPersonProfileRepository($f->sql)->save(
                $id,
                $after->givenName,
                $after->familyName,
                $after->directoryRestricted,
            );
        });
    }

    private function records(TranscriptFixture $t): StudentRecordService
    {
        $f = $t->sis;
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

    private function selectAnotherProgram(TranscriptFixture $t, EntityId $student): StudentProgram
    {
        $f = $t->sis;
        $program = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "HIST.1",
            "title" => "Fictional selected program",
        ]);
        $id = $f->assignProgram($student, $program, $f->term);
        $review = $f
            ->primaryProgramService()
            ->review(
                $student,
                $id,
                $this->records($t)->view($student)["revision"],
                "Fictional selected primary pursuit.",
            );
        $f->primaryProgramService()->commit($student, $id, $review["review_token"]);
        return new SqlStudentRecordRepository($f->sql)->assignment($id);
    }

    public function testIdentityChangesRequireFreshPreparationButContactChangesDoNot(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $f = $t->sis;
        $student = $f->admissions->student(new EntityId($t->repository->request($request)["student_id"]));
        $this->rename($t, $student->personId, "Fictional first name");
        $service = $this->service($t);
        $document = $this->prepare($service, $request);
        $original = $service->view($request, $document)["snapshot"];
        self::assertSame(2, $original["identity_verification"]["core_version"]);
        self::assertTrue($original["identity_verification"]["earlier_history_unknown"]);
        $t->campus->contacts->staffSave($student->personId, "Fictional nickname", "555-0100", "", 0);
        self::assertFalse($service->view($request, $document)["academic_changed"]);
        $current = $service->review($request)["snapshot"];
        self::assertSame($original["identity_verification"], $current["identity_verification"]);
        self::assertStringNotContainsString("555-0100", json_encode($current, JSON_THROW_ON_ERROR));
        $this->rename($t, $student->personId, "Fictional corrected name");
        self::assertTrue($service->view($request, $document)["academic_changed"]);
        self::assertStringContainsString(
            "Fictional first name",
            $service->view($request, $document)["snapshot"]["student"]["name"],
        );
        self::assertStringContainsString(
            "Fictional corrected name",
            $service->review($request)["snapshot"]["student"]["name"],
        );
    }

    public function testProfileProjectionAndJournalDamageCannotSupplyNewDocumentIdentity(): void
    {
        $cases = ["name", "identifier", "journal"];
        foreach ($cases as $damage) {
            $t = new TranscriptFixture();
            $request = $t->request();
            $f = $t->sis;
            $student = $f->admissions->student(new EntityId($t->repository->request($request)["student_id"]));
            $this->rename($t, $student->personId, "Fictional recorded identity");
            $service = $this->service($t);
            $document = $this->prepare($service, $request);
            if ($damage === "name") {
                $f->sql->update(
                    "sis_persons",
                    ["given_name" => "Unrecorded overwrite"],
                    ["id" => $student->personId->value],
                );
            } elseif ($damage === "identifier") {
                $f->sql->update(
                    "sis_person_identifiers",
                    ["institutional_id" => "UNRECORDED-ID"],
                    ["person_id" => $student->personId->value],
                );
            } else {
                $head = $f->sql->one("sis_history_streams", [
                    "stream_type" => "person_profile",
                    "aggregate_id" => $student->personId->value,
                ]);
                $f->sql->update(
                    "sis_history_events",
                    ["event_hash" => str_repeat("0", 64)],
                    ["stream_id" => $head["id"]],
                );
            }
            $events = $f->sql->rows("sis_history_events");
            try {
                $service->review($request);
                self::fail("Damaged identity became a new transcript header.");
            } catch (ValidationException) {
                self::assertSame("unavailable", $service->view($request, $document)["academic_check"]);
                self::assertStringContainsString("%PDF-", $service->pdf($request, $document));
                self::assertSame($events, $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testRecordedPrimarySelectionProtectsTheCanonicalPersonAndProgramPointers(): void
    {
        $cases = ["person", "program", "marker"];
        foreach ($cases as $damage) {
            $t = new TranscriptFixture();
            $request = $t->request();
            $f = $t->sis;
            $id = new EntityId($t->repository->request($request)["student_id"]);
            $this->selectAnotherProgram($t, $id);
            $other = $f->student();
            if ($damage === "person") {
                $unattached = $f->legacyPerson($f->user(), "Fictional", "Unattached");
                $f->sql->update(
                    "sis_students",
                    ["person_id" => $unattached->id->value],
                    ["id" => $id->value],
                );
            } elseif ($damage === "program") {
                $f->sql->update(
                    "sis_students",
                    ["primary_program_id" => $other->programId->value],
                    ["id" => $id->value],
                );
            } else {
                $f->sql->update(
                    "sis_primary_program_streams",
                    ["stream_version" => 99],
                    ["id" => $id->value],
                );
            }
            $this->expectExceptionForRequest($this->service($t), $request);
            self::assertSame([], $f->sql->rows("sis_transcript_documents"));
        }
    }

    private function expectExceptionForRequest(TranscriptFulfillmentService $service, EntityId $request): void
    {
        try {
            $service->review($request);
            self::fail("Unverified source pointers were accepted.");
        } catch (ValidationException $error) {
            self::assertSame("academic_history", $error->errors[0]["field"]);
        }
    }

    public function testClosedRecordedPrimaryRemainsReadableWithoutGrantingRegistrationEligibility(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $f = $t->sis;
        $id = new EntityId($t->repository->request($request)["student_id"]);
        $originalProgram = $f->admissions->student($id)->programId;
        $selected = $this->selectAnotherProgram($t, $id);
        $former = null;
        foreach ($this->records($t)->programs($id)["items"] as $candidate) {
            if ($candidate["program_id"] === $originalProgram->value) {
                $former = $candidate;
                break;
            }
        }
        self::assertNotNull($former);
        $formerId = new EntityId($former["id"]);
        $review = $f->programStatusService()->review(
            $id,
            $formerId,
            false,
            $former["revision"],
            "Fictional former assignment closure.",
        );
        $receipt = $f->programStatusService()->commit($id, $formerId, $review["review_token"]);
        // Recorded closure fixture for read contracts; this does not implement graduation authoring.
        $f->transactions->run(function () use ($f, $id, $selected): void {
            $f->registration->lockStudent($id);
            $f->programStatusLedger()->change(
                $selected,
                "closed",
                EntityId::generate(),
                $f->access->actor,
                "Fictional recorded end of pursuit.",
            );
        });
        $events = $f->sql->rows("sis_history_events");
        $view = $this->records($t)->view($id);
        self::assertSame("closed", $view["primary_program_status"]);
        $programs = array_column($this->records($t)->programs($id)["items"], null, "id");
        self::assertTrue($programs[$selected->id->value]["is_primary"]);
        self::assertFalse($programs[$formerId->value]["is_primary"]);
        self::assertSame(
            $selected->id->value,
            $f->primaryProgramService()->history($id)["current_selection"]["assignment_id"],
        );
        self::assertTrue($f->programStatusService()->history($id, $selected->id)["history_recorded"]);
        self::assertSame(
            $receipt,
            $f->programStatusService()->commit($id, $formerId, $review["review_token"]),
        );
        $snapshot = $this->service($t)->review($request)["snapshot"];
        self::assertSame("closed", $snapshot["program_verification"]["status"]);
        self::assertSame($selected->id->value, $snapshot["program_verification"]["assignment_id"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $this->expectException(CorruptHistoryException::class);
        $f->primaryProgramLedger()->verify($f->admissions->student($id));
    }

    public function testProgramHistoryRechecksAuthorityAfterTheRequiredAudit(): void
    {
        $cases = ["primary", "assignment"];
        foreach ($cases as $kind) {
            $t = new TranscriptFixture();
            $request = $t->request();
            $f = $t->sis;
            $id = new EntityId($t->repository->request($request)["student_id"]);
            $selected = $this->selectAnotherProgram($t, $id);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(static function () use ($f): void {
                $f->access->actor = $f->user();
            });
            try {
                if ($kind === "primary") {
                    $f->primaryProgramService($audit)->history($id);
                } else {
                    $f->programStatusService($audit)->history($id, $selected->id);
                }
                self::fail("Changed actor received private program history.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCohortPublicationLabelsSurviveDraftChangesAndLaterAcademicYears(): void
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $p = new ProgramPublicationFixture($f);
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional original cohort.");
        $publication = $p->service->publish($f->program, $review["review_token"]);
        $person = $f->legacyPerson($f->user(), "Fictional", "Cohort student");
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($year["terms"][0]["id"]));
        $f->legacyAdmissionDecision($application->id, true);
        $student = $f->matriculate($application->id);
        $request = EntityId::generate();
        $actor = $f->access->actor;
        $f->access->actor = $person->ownerUserId;
        try {
            $t->campus->service->requestTranscript($request, $student->id, "pickup", "");
        } finally {
            $f->access->actor = $actor;
        }
        $service = $this->service($t);
        $document = $this->prepare($service, $request);
        $original = $service->view($request, $document)["snapshot"];
        self::assertSame($publication["operation_id"], $original["catalog"]["id"]);
        self::assertTrue($original["program_verification"]["definition_recorded"]);
        $draft = $p->service->draft($f->program);
        $p->service->save(
            $f->program,
            "FUTURE",
            "Fictional future title",
            "Future description",
            null,
            null,
            null,
            $draft["revision"],
        );
        $later = $p->calendar->year(2025);
        $next = $p->service->review($f->program, new EntityId($later["id"]), "Fictional later cohort.");
        $p->service->publish($f->program, $next["review_token"]);
        self::assertFalse($service->view($request, $document)["academic_changed"]);
        self::assertSame($original["program"], $service->review($request)["snapshot"]["program"]);
        $assignment = new SqlStudentRecordRepository($f->sql)->assignment(
            new EntityId($original["program_verification"]["assignment_id"]),
        );
        $f->transactions->run(function () use ($f, $student, $assignment): void {
            $f->registration->lockStudent($student->id);
            $f->programStatusLedger()->change(
                $assignment,
                "closed",
                EntityId::generate(),
                $f->access->actor,
                "Fictional closed cohort pursuit.",
            );
        });
        $closed = $service->review($request)["snapshot"];
        self::assertSame("closed", $closed["program_verification"]["status"]);
        self::assertSame($original["catalog"], $closed["catalog"]);
        self::assertSame($original["program"], $closed["program"]);
        self::assertSame($original["catalog"], $this->records($t)->view($student->id)["catalog"]);
        $otherProgram = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $otherProgram->value,
            "code" => "OTHER",
            "title" => "Fictional other program",
        ]);
        $prior = $f->sql->one("sis_program_catalogs", ["id" => $publication["operation_id"]]);
        $cases = [["revision" => 99], ["program_id" => $otherProgram->value], ["published" => 0]];
        foreach ($cases as $changes) {
            $f->sql->update("sis_program_catalogs", $changes, ["id" => $publication["operation_id"]]);
            $this->expectExceptionForRequest($service, $request);
            $f->sql->update("sis_program_catalogs", array_intersect_key($prior, $changes), [
                "id" => $publication["operation_id"],
            ]);
        }
    }

    public function testUntrackedClosedPursuitsRequireAnUnambiguousSelection(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $f = $t->sis;
        $id = new EntityId($t->repository->request($request)["student_id"]);
        $f->sql->update("sis_student_programs", ["status" => "closed"], ["student_id" => $id->value]);
        self::assertSame(
            "closed",
            $this->service($t)->review($request)["snapshot"]["program_verification"]["status"],
        );
        $term = $f->sql->one("sis_terms", ["id" => $f->term->value]);
        $newTerm = EntityId::generate();
        $f->sql->insert("sis_terms", array_replace($term, ["id" => $newTerm->value, "code" => "27/OLD"]));
        new SqlStudentRecordRepository($f->sql)->addAssignment(
            new StudentProgram(EntityId::generate(), $id, $f->program, $newTerm, "closed"),
        );
        $this->expectExceptionForRequest($this->service($t), $request);
    }

    public function testIdentityReplayUsesTheSuppliedSharedBudgetWithoutPartialReferences(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $f = $t->sis;
        $student = $f->admissions->student(new EntityId($t->repository->request($request)["student_id"]));
        $this->rename($t, $student->personId, "Fictional bounded identity");
        $this->expectException(HistoryReadLimitException::class);
        $f->transactions->run(function () use ($f, $student): void {
            $f->admissions->lockPerson($student->personId);
            $f->profileLedger()->coreReference(
                $f->admissions->person($student->personId),
                new HistoryReadBudget(1, 1),
            );
        });
    }
}
