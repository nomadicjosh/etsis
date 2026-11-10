<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;

final class AdmissionDecisionHistoryTest extends TestCase
{
    private function application(SisFixture $f): AdmissionApplication
    {
        $service = $f->admissionsService();
        $person = $service->createPerson($f->user(), "Fictional", "Applicant");
        return $service->apply($person->id, $f->program, $f->term);
    }
    private function forms(SisFixture $f): ApplicationFormService
    {
        return new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
        );
    }
    public function testReviewWritesNothingAndBothDecisionsReplayWithAttributedProtectedHistory(): void
    {
        foreach ([true, false] as $accept) {
            $f = new SisFixture();
            $a = $this->application($f);
            $service = $f->decisionService();
            $review = $service->review($a->id, $accept, "Fictional protected decision reason");
            self::assertSame($accept ? "accepted" : "rejected", $review["status"]);
            self::assertSame([], $f->sql->rows("sis_history_events"));
            self::assertSame("submitted", $f->admissions->application($a->id)->status->value);
            $result = $service->commit($a->id, $review["review_token"]);
            self::assertSame($review["status"], $result["status"]);
            self::assertSame(1, $result["version"]);
            self::assertCount(2, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_students"));
            $history = $service->history($a->id);
            self::assertTrue($history["history_recorded"]);
            self::assertTrue($history["earlier_history_unknown"]);
            self::assertSame($result, $history["decision"]);
            self::assertNull($history["source"]["submission"]);
            $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
            $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
                new AdmissionDecisionId($a->id->value),
            );
            foreach ($events as $event) {
                $root->acceptHistorical($event);
            }
            self::assertSame($result["status"], $root->application()["status"]);
            $serialized = json_encode([$history, $f->sql->rows("sis_history_events")], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString("Fictional protected decision reason", $serialized);
            self::assertStringNotContainsString("actor_id", json_encode($history, JSON_THROW_ON_ERROR));
        }
    }
    public function testAcceptanceRetryAfterMatriculationReturnsItsEarlierOutcomeWithoutChangingStudentFacts(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $service = $f->decisionService();
        $review = $service->review($a->id, true, "Fictional acceptance");
        $result = $service->commit($a->id, $review["review_token"]);
        $student = $f->matriculate($a->id);
        self::assertFalse($f->matriculationService()->history($a->id)["acceptance_history_unknown"]);
        $before = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($result, $service->commit($a->id, $review["review_token"]));
        self::assertSame("matriculated", $f->admissions->application($a->id)->status->value);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame($student->id->value, $f->sql->rows("sis_students")[0]["id"]);
        self::assertSame("matriculated", $service->history($a->id)["current_status"]);
    }
    public function testACompetingReviewCannotOverwriteTheWinningDecision(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $s = $f->decisionService();
        $accept = $s->review($a->id, true, "Fictional acceptance");
        $reject = $s->review($a->id, false, "Fictional rejection");
        $s->commit($a->id, $reject["review_token"]);
        try {
            $s->commit($a->id, $accept["review_token"]);
            self::fail("A second decision was accepted.");
        } catch (ValidationException) {
            self::assertSame("rejected", $f->admissions->application($a->id)->status->value);
            self::assertCount(2, $f->sql->rows("sis_history_events"));
        }
        $this->expectException(ValidationException::class);
        $f->matriculationService()->review($a->id, "Cannot matriculate a rejection");
    }
    public function testSubmittedFormRevisionIsReferencedWithoutCopyingAnswersAndSurvivesReplacement(): void
    {
        $f = new SisFixture();
        $forms = $this->forms($f);
        $person = $f->admissionsService()->createPerson($f->user(), "Fictional", "Applicant");
        $fields = [
            ["key" => "statement", "label" => "Personal statement", "type" => "textarea", "required" => true],
        ];
        $form = $forms->save(null, $f->program, "Original fictional form", $fields, null);
        $forms->publish($form->id, 0);
        $submission = EntityId::generate();
        $a = $forms->submit($submission, $form->id, $person->id, $f->term, [
            "statement" => "Fictional private statement",
        ]);
        $s = $f->decisionService();
        $review = $s->review($a, true, "Fictional reason");
        self::assertSame("Original fictional form", $review["source"]["submission"]["form_name"]);
        self::assertArrayNotHasKey("fingerprint", $review["source"]["submission"]);
        $s->commit($a, $review["review_token"]);
        $history = $s->history($a);
        $new = $forms->save(null, $f->program, "Replacement fictional form", $fields, null);
        $forms->publish($new->id, 0);
        $f->sql->update("sis_programs", ["title" => "Later fictional title"], ["id" => $f->program->value]);
        $f->sql->update("sis_terms", ["code" => "LATER.LABEL"], ["id" => $f->term->value]);
        self::assertSame($history, $s->history($a));
        $events = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new AdmissionDecisionId($a->value),
        );
        $eventPayload = null;
        foreach ($events as $event) {
            if ($event instanceof AdmissionWasDecided) {
                $eventPayload = $event->payload();
            }
        }
        self::assertSame($submission->value, $eventPayload["source"]["submission"]["id"]);
        self::assertSame($form->id->value, $eventPayload["source"]["submission"]["form_id"]);
        self::assertStringNotContainsString(
            "Fictional private statement",
            json_encode($eventPayload, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString("fingerprint", json_encode($history, JSON_THROW_ON_ERROR));
    }
    public function testChangedProgramPersonTermOrApplicationVersionRequiresANewReview(): void
    {
        foreach (["program", "person", "term", "version"] as $change) {
            $f = new SisFixture();
            $a = $this->application($f);
            $s = $f->decisionService();
            $review = $s->review($a->id, true, "Fictional reason");
            match ($change) {
                "program" => $f->sql->update(
                    "sis_programs",
                    ["title" => "Changed fictional title"],
                    ["id" => $f->program->value],
                ),
                "person" => $f->admissionsService()->updateInstitutionalId($a->personId, "TEST-CHANGED", 0),
                "term" => $f->sql->update("sis_terms", ["code" => "NEW.TERM"], ["id" => $f->term->value]),
                "version" => $f->sql->update("sis_applications", ["version" => 2], ["id" => $a->id->value]),
            };
            try {
                $s->commit($a->id, $review["review_token"]);
                self::fail("A stale review was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testAChangedSubmissionInvalidatesTheReviewEvenIfItsStoredFingerprintWasNotUpdated(): void
    {
        $f = new SisFixture();
        $forms = $this->forms($f);
        $person = $f->admissionsService()->createPerson($f->user(), "Fictional", "Applicant");
        $form = $forms->save(
            null,
            $f->program,
            "Fictional form",
            [["key" => "note", "label" => "Note", "type" => "text"]],
            null,
        );
        $forms->publish($form->id, 0);
        $id = EntityId::generate();
        $app = $forms->submit($id, $form->id, $person->id, $f->term, ["note" => "First fictional note"]);
        $s = $f->decisionService();
        $review = $s->review($app, true, "Fictional reason");
        $f->sql->update(
            "sis_form_submissions",
            ["encrypted_answers" => $f->dropCipher->seal('{"note":"Changed fictional note"}')],
            ["id" => $id->value],
        );
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($app, $review["review_token"]);
    }
    public function testAuditFailureRollsBackApplicationBothEventsHeadsAndReceiptsThenAllowsRetry(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        $review = $f->decisionService()->review($a->id, true, "Fictional reason");
        try {
            $f->decisionService($audit)->commit($a->id, $review["review_token"]);
            self::fail("Partial acceptance committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure", $e->getMessage());
        }
        self::assertSame("submitted", $f->admissions->application($a->id)->status->value);
        self::assertSame(0, $f->admissions->application($a->id)->version);
        foreach (["sis_history_events", "sis_history_streams", "sis_history_operations"] as $table) {
            self::assertSame([], $f->sql->rows($table));
        }
        $f->decisionService()->commit($a->id, $review["review_token"]);
        self::assertCount(2, $f->sql->rows("sis_history_events"));
    }
    public function testOldAcceptedAndRejectedApplicationsRemainUntrackedAndDoNotAcquireInventedDecisions(): void
    {
        foreach ([true, false] as $accept) {
            $f = new SisFixture();
            $a = $this->application($f);
            $f->legacyAdmissionDecision($a->id, $accept);
            self::assertFalse($f->decisionService()->history($a->id)["history_recorded"]);
            self::assertSame([], $f->sql->rows("sis_history_events"));
            try {
                $f->decisionService()->review($a->id, $accept, "Fictional reason");
                self::fail("Invented decision allowed.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testExpiredReviewsAndReviewsCrossingTheInstitutionalDateAreRejected(): void
    {
        foreach (["+21 minutes", "2026-08-11T00:01:00-07:00"] as $time) {
            $f = new SisFixture();
            $a = $this->application($f);
            $review = $f->decisionService()->review($a->id, true, "Fictional reason");
            $f->clock->time =
            $time[0] === "+" ? $f->clock->time->modify($time) : new DateTimeImmutable($time);
            try {
                $f->decisionService()->commit($a->id, $review["review_token"]);
                self::fail("Expired review accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testOwnershipStudentReadAndRegistrarPermissionsDoNotGrantAdmissionsDecisions(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->actor = $f->admissions->person($a->personId)->ownerUserId;
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read", "sis:registration:manage"];
        $this->expectException(AccessDeniedException::class);
        $f->decisionService()->review($a->id, true, "Fictional forbidden decision");
    }
    public function testActorApplicationPurposeAndPermissionBindingsAreEnforcedOnCommit(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $other = $this->application($f);
        $review = $f->decisionService()->review($a->id, true, "Fictional reason");
        foreach (["actor", "application", "damaged", "permission", "purpose"] as $case) {
            $actor = $f->access->actor;
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "permission") {
                $f->access->staff = false;
            }
            $token = $review["review_token"];
            if ($case === "damaged") {
                $token .= "!";
            }
            if ($case === "purpose") {
                $token = $f->dropCipher->seal('{"purpose":"matriculation.v1"}');
            }
            try {
                $f->decisionService()->commit($case === "application" ? $other->id : $a->id, $token);
                self::fail("Invalid authority accepted.");
            } catch (ValidationException | AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            } finally {
                $f->access->actor = $actor;
                $f->access->staff = true;
            }
        }
    }
    public function testPermissionIsRecheckedAfterWaitingForApplicationLocks(): void
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
        $repo->expects(self::never())->method("saveDecision");
        $this->expectException(AccessDeniedException::class);
        $f->decisionService(repository: $repo)->review($a->id, true, "Fictional reason");
    }
    public function testConflictingDecisionProjectionBlocksReviewHistoryAndMatriculation(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $review = $f->decisionService()->review($a->id, false, "Fictional rejection");
        $f->decisionService()->commit($a->id, $review["review_token"]);
        $f->sql->update("sis_applications", ["status" => "accepted"], ["id" => $a->id->value]);
        foreach (["history", "read", "matriculation"] as $action) {
            try {
                match ($action) {
                    "history" => $f->decisionService()->history($a->id),
                    "read" => $f->admissionsService()->review($a->id),
                    "matriculation" => $f->matriculationService()->review($a->id, "Fictional matriculation"),
                };
                self::fail("A conflicting projection was trusted.");
            } catch (CorruptHistoryException) {
                self::assertSame([], $f->sql->rows("sis_students"));
            }
        }
    }
    public function testChangingAcceptedToMatriculatedWithoutItsCreationEventIsRejected(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $review = $f->decisionService()->review($a->id, true, "Fictional acceptance");
        $f->decisionService()->commit($a->id, $review["review_token"]);
        $f->sql->update(
            "sis_applications",
            ["status" => "matriculated", "version" => 2],
            ["id" => $a->id->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $f->decisionService()->commit($a->id, $review["review_token"]);
    }
    public function testReasonValidationAndLongUnicodeReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        foreach (["", "bad\x00reason", "\xFF", str_repeat("a", 10001)] as $reason) {
            try {
                $f->decisionService()->review($a->id, true, $reason);
                self::fail("Invalid reason accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
        $review = $f->decisionService()->review($a->id, true, str_repeat("学", 10000));
        $f->decisionService()->commit($a->id, $review["review_token"]);
        self::assertCount(2, $f->sql->rows("sis_history_events"));
    }
    public function testFrozenSchemasAndRootRejectMalformedAndRepeatedDecisions(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $id = new AdmissionDecisionId($a->id->value);
        $observed = AdmissionApplicationWasObserved::occur(
            $id,
            [
                "decision_id" => EntityId::generate()->value,
                "application" => AdmissionDecisionSchema::facts($a),
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead(1);
        $source = [
            "program_code" => "BS.CS",
            "program_title" => "Fictional computing",
            "entry_term_code" => "26/FA",
            "submission" => null,
        ];
        $payload = [
            "decision_id" => EntityId::generate()->value,
            "previous_decision_id" => $observed->payload()["decision_id"],
            "status" => "accepted",
            "application_version" => 1,
            "source" => $source,
        ];
        $invalid = [
            $payload + ["extra" => true],
            array_replace($payload, ["status" => "matriculated"]),
            array_replace($payload, ["application_version" => true]),
            array_replace($payload, ["decision_id" => "invalid"]),
        ];
        foreach ($invalid as $p) {
            try {
                AdmissionDecisionSchema::validate(AdmissionWasDecided::class, $p);
                self::fail("Invalid event accepted.");
            } catch (CorruptHistoryException | InvalidArgumentException $error) {
                self::assertNotSame("", $error->getMessage());
            }
        }
        $root = AdmissionDecision::root($id);
        $root->acceptHistorical($observed);
        $event = AdmissionWasDecided::occur($id, $payload, [
            "__aggregate_type" => "admission_decision",
        ])->withPlayhead(2);
        $root->acceptHistorical($event);
        self::assertSame("accepted", $root->application()["status"]);
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($event->withPlayhead(3));
    }
    public function testSubmissionRechecksTheOwnerAfterWaitingForThePersonLock(): void
    {
        $f = new SisFixture();
        $person = $f->admissionsService()->createPerson($f->access->actor, "Fictional", "Applicant");
        $repo = $this->createMock(\Domain\Admissions\Repository\ApplicationFormRepository::class);
        $repo
            ->expects(self::once())
            ->method("lockPerson")
            ->willReturnCallback(function () use ($f): void {
                $f->access->actor = $f->user();
                $f->access->staff = false;
            });
        $repo->expects(self::never())->method("get");
        $forms = new ApplicationFormService(
            $repo,
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
        );
        $this->expectException(AccessDeniedException::class);
        $forms->submit(EntityId::generate(), EntityId::generate(), $person->id, $f->term, []);
    }
}
