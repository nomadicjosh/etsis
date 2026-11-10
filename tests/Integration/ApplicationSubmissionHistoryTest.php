<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Event\AdmissionApplicationWasSubmitted;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\ApplicationSubmissionSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;

final class ApplicationSubmissionHistoryTest extends TestCase
{
    private function person(SisFixture $f): EntityId
    {
        return $f->admissionsService()->createPerson($f->access->actor, "Fictional", "Applicant")->id;
    }
    private function forms(SisFixture $f): ApplicationFormService
    {
        return new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService(),
        );
    }
    private function form(SisFixture $f): EntityId
    {
        $forms = $this->forms($f);
        $form = $forms->save(
            null,
            $f->program,
            "Fictional original form",
            [["key" => "statement", "label" => "Statement", "type" => "textarea", "required" => true]],
            null,
        );
        $forms->publish($form->id, 0);
        return $form->id;
    }
    private function events(SisFixture $f, EntityId $app): array
    {
        return iterator_to_array(
            EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
                new AdmissionDecisionId($app->value),
            ),
        );
    }
    public function testOwnerFormSubmissionRecordsItsActualBirthWithoutCopyingAnswersIntoEvents(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $f->access->staff = false;
        $app = $this->forms($f)->submit($id, $form, $person, $f->term, [
            "statement" => "Fictional private statement",
        ]);
        self::assertCount(1, $f->sql->rows("sis_history_events"));
        $events = $this->events($f, $app);
        self::assertInstanceOf(AdmissionApplicationWasSubmitted::class, $events[0]);
        $p = $events[0]->payload();
        self::assertSame("owner", $p["authorization"]);
        self::assertSame("form", $p["channel"]);
        self::assertSame($id->value, $p["decision_id"]);
        self::assertSame($id->value, $p["source"]["submission"]["id"]);
        self::assertSame($form->value, $p["source"]["submission"]["form_id"]);
        self::assertStringNotContainsString(
            "Fictional private statement",
            json_encode($p, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            "Fictional private statement",
            $this->forms($f)->submission($id)["answers"]["statement"],
        );
        $f->access->staff = true;
        $history = $f->decisionService()->history($app);
        self::assertFalse($history["history_recorded"]);
        self::assertFalse($history["earlier_history_unknown"]);
        self::assertSame("2026-08-10T10:00:00-07:00", $history["submission"]["effective_at"]);
        self::assertSame(
            "Fictional original form",
            $history["submission"]["source"]["submission"]["form_name"],
        );
        self::assertStringNotContainsString("fingerprint", json_encode($history, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(
            "form_definition_hash",
            json_encode($history, JSON_THROW_ON_ERROR),
        );
    }
    public function testDirectEntryIsIdempotentAndStartsTheSameStreamBeforeDecisionAndMatriculation(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $id = EntityId::generate();
        $s = $f->submissionService();
        $a = $s->apply($id, $person, $f->program, $f->term);
        self::assertSame($id->value, $a->id->value);
        self::assertSame("direct", $this->events($f, $id)[0]->payload()["channel"]);
        $review = $f->decisionService()->review($id, true, "Fictional acceptance");
        $f->decisionService()->commit($id, $review["review_token"]);
        $student = $f->matriculate($id);
        $before = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+1 day");
        $retry = $s->apply($id, $person, $f->program, $f->term);
        self::assertSame("submitted", $retry->status->value);
        self::assertSame("matriculated", $f->admissions->application($id)->status->value);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_students"));
        self::assertSame($student->personId->value, $person->value);
        self::assertSame(
            [AdmissionApplicationWasSubmitted::class, AdmissionWasDecided::class],
            array_map(static fn($e): string => $e::class, $this->events($f, $id)),
        );
        self::assertFalse($f->decisionService()->history($id)["earlier_history_unknown"]);
        self::assertFalse($f->matriculationService()->history($id)["acceptance_history_unknown"]);
    }
    public function testFormRetrySurvivesReplacementAndMatriculationWithoutAnotherBirthOrStudent(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $app = $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        $review = $f->decisionService()->review($app, true, "Fictional acceptance");
        $f->decisionService()->commit($app, $review["review_token"]);
        $f->matriculate($app);
        $replacement = $this->form($f);
        self::assertNotSame($form->value, $replacement->value);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(
            $app->value,
            $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"])
                ->value,
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_form_submissions"));
    }
    public function testSubmissionIsRetainedThroughRejectionAndReplaysWithoutAnObservation(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $app = $this->forms($f)->submit(EntityId::generate(), $form, $person, $f->term, [
            "statement" => "Fictional reason",
        ]);
        $review = $f->decisionService()->review($app, false, "Fictional rejection");
        $f->decisionService()->commit($app, $review["review_token"]);
        $root = AdmissionDecision::root(new AdmissionDecisionId($app->value));
        foreach ($this->events($f, $app) as $e) {
            $root->acceptHistorical($e);
        }
        self::assertSame("rejected", $root->application()["status"]);
        self::assertNotNull($root->submission());
        self::assertSame(2, $root->playhead());
        self::assertFalse($f->decisionService()->history($app)["earlier_history_unknown"]);
    }
    public function testChangedAnswersWithOrWithoutAChangedProjectionFingerprintBlockReadsAndDecisions(): void
    {
        foreach ([false, true] as $changeFingerprint) {
            $f = new SisFixture();
            $person = $this->person($f);
            $form = $this->form($f);
            $id = EntityId::generate();
            $app = $this->forms($f)->submit($id, $form, $person, $f->term, [
                "statement" => "Fictional original",
            ]);
            $change = [
                "encrypted_answers" => $f->dropCipher->seal('{"statement":"Changed fictional answer"}'),
            ];
            if ($changeFingerprint) {
                $change["fingerprint"] = hash("sha256", "Changed fingerprint");
            }
            $f->sql->update("sis_form_submissions", $change, ["id" => $id->value]);
            foreach (["owner-read", "review", "decision"] as $action) {
                try {
                    match ($action) {
                        "owner-read" => $this->forms($f)->submission($id),
                        "review" => $f->admissionsService()->review($app),
                        "decision" => $f->decisionService()->review($app, true, "Fictional acceptance"),
                    };
                    self::fail("A conflicting answer projection was accepted.");
                } catch (CorruptHistoryException) {
                    self::assertCount(1, $f->sql->rows("sis_history_events"));
                }
            }
        }
    }
    public function testChangedPublishedFormDefinitionBlocksReadsWhileChangingCatalogLabelsDoesNot(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $app = $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        $history = $f->decisionService()->history($app);
        $f->sql->update(
            "sis_programs",
            ["title" => "Changed fictional catalog label"],
            ["id" => $f->program->value],
        );
        $f->sql->update("sis_terms", ["code" => "LATER.LABEL"], ["id" => $f->term->value]);
        self::assertSame($history, $f->decisionService()->history($app));
        $f->sql->update("sis_application_forms", ["fields_json" => "[]"], ["id" => $form->value]);
        $this->expectException(CorruptHistoryException::class);
        $this->forms($f)->submission($id);
    }
    public function testReencryptingTheSameAnswersPreservesTheirVerifiedReference(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $app = $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        $row = $f->sql->one("sis_form_submissions", ["id" => $id->value]);
        $f->sql->update(
            "sis_form_submissions",
            ["encrypted_answers" => $f->dropCipher->seal($f->dropCipher->open($row["encrypted_answers"]))],
            ["id" => $id->value],
        );
        self::assertSame("Fictional original", $this->forms($f)->submission($id)["answers"]["statement"]);
        self::assertNotNull($f->decisionService()->history($app)["submission"]);
    }
    public function testStaffAssistedSubmissionBelongsToThePersonForReadsButItsRetryRemainsActorBound(): void
    {
        $f = new SisFixture();
        $owner = $f->user();
        $person = $f->admissionsService()->createPerson($owner, "Fictional", "Applicant")->id;
        $form = $this->form($f);
        $id = EntityId::generate();
        $app = $this->forms($f)->submit($id, $form, $person, $f->term, [
            "statement" => "Fictional assisted statement",
        ]);
        self::assertSame(
            "admissions_staff",
            $f->decisionService()->history($app)["submission"]["authorization"],
        );
        $f->access->actor = $owner;
        $f->access->staff = false;
        self::assertSame(
            "Fictional assisted statement",
            $this->forms($f)->submission($id)["answers"]["statement"],
        );
        $this->expectException(ValidationException::class);
        $this->forms($f)->submit($id, $form, $person, $f->term, [
            "statement" => "Fictional assisted statement",
        ]);
    }
    public function testAnotherPersonAndStudentReadPermissionCannotReadOrSubmitForThisApplicant(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read"];
        foreach (["read", "form", "direct"] as $action) {
            try {
                match ($action) {
                    "read" => $this->forms($f)->submission($id),
                    "form" => $this->forms($f)->submit(EntityId::generate(), $form, $person, $f->term, [
                        "statement" => "Fictional forbidden",
                    ]),
                    "direct" => $f
                        ->submissionService()
                        ->apply(EntityId::generate(), $person, $f->program, $f->term),
                };
                self::fail("Another applicant record was exposed.");
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows("sis_applications"));
            }
        }
    }
    public function testChangedRequestCannotReuseAFormOrDirectApplicationIdentifier(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        try {
            $this->forms($f)->submit($id, $form, $person, $f->term, [
                "statement" => "Changed fictional statement",
            ]);
            self::fail("Changed request accepted.");
        } catch (ValidationException) {
            self::assertCount(1, $f->sql->rows("sis_form_submissions"));
        }
        $a = $f->submissionService()->apply(EntityId::generate(), $person, $f->program, $f->term);
        $other = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $other->value,
            "code" => "BA.ART",
            "title" => "Fictional arts",
        ]);
        $this->expectException(ConcurrentModificationException::class);
        $f->submissionService()->apply($a->id, $person, $other, $f->term);
    }
    public function testAuditFailureRollsBackApplicationAnswersBirthHeadAndReceiptAndAllowsRetry(): void
    {
        foreach (["direct", "form"] as $channel) {
            $f = new SisFixture();
            $person = $this->person($f);
            $form = $this->form($f);
            $id = EntityId::generate();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
            $s = $f->submissionService($audit);
            try {
                if ($channel === "direct") {
                    $s->apply($id, $person, $f->program, $f->term);
                } else {
                    $s->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
                }
                self::fail("A partial application committed.");
            } catch (RuntimeException $error) {
                self::assertSame("Fictional audit failure", $error->getMessage());
            }
            $tables = [
                "sis_applications",
                "sis_form_submissions",
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
            ];
            foreach ($tables as $table) {
                self::assertSame([], $f->sql->rows($table));
            }
            if ($channel === "direct") {
                $f->submissionService()->apply($id, $person, $f->program, $f->term);
            } else {
                $f->submissionService()->submit($id, $form, $person, $f->term, [
                    "statement" => "Fictional original",
                ]);
            }
            self::assertCount(1, $f->sql->rows("sis_history_events"));
        }
    }
    public function testInvalidAnswersAndInactiveFormCreateNoApplicationOrBirth(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        try {
            $this->forms($f)->submit(EntityId::generate(), $form, $person, $f->term, []);
            self::fail("Missing required answer accepted.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_history_events"));
        }
        $this->forms($f)->disable($f->program);
        try {
            $this->forms($f)->submit(EntityId::generate(), $form, $person, $f->term, [
                "statement" => "Fictional original",
            ]);
            self::fail("Inactive form accepted.");
        } catch (\DomainException | \Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame([], $f->sql->rows("sis_applications"));
        }
    }
    public function testOldApplicationsNeverAcquireInventedBirthEventsDuringDecisionsOrReads(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $a = $f->legacyApplication($person, $f->program, $f->term);
        self::assertNull($f->decisionService()->history($a->id)["submission"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
        $r = $f->decisionService()->review($a->id, true, "Fictional acceptance");
        $f->decisionService()->commit($a->id, $r["review_token"]);
        self::assertTrue($f->decisionService()->history($a->id)["earlier_history_unknown"]);
        self::assertNull($f->decisionService()->history($a->id)["submission"]);
        self::assertNotInstanceOf(AdmissionApplicationWasSubmitted::class, $this->events($f, $a->id)[0]);
    }
    public function testFrozenSubmissionSchemaRejectsWrongChannelReferencesAndDuplicateBirth(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $a = $f->submissionService()->apply(EntityId::generate(), $person, $f->program, $f->term);
        $e = $this->events($f, $a->id)[0];
        $p = $e->payload();
        $invalid = [
            $p + ["extra" => true],
            array_replace($p, ["channel" => "form"]),
            array_replace($p, ["authorization" => "guest"]),
            array_replace($p, ["decision_id" => EntityId::generate()->value]),
            array_replace($p, ["form_definition_hash" => "unexpected"]),
        ];
        foreach ($invalid as $payload) {
            try {
                ApplicationSubmissionSchema::validate($payload);
                self::fail("Invalid birth event accepted.");
            } catch (CorruptHistoryException | InvalidArgumentException $error) {
                self::assertNotSame("", $error->getMessage());
            }
        }
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        $root->acceptHistorical($e);
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($e->withPlayhead(2));
    }
    public function testAnOlderFormReceiptCanBeRetriedWithoutInventingSubmissionHistory(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $a = $f->legacyApplication($person, $f->program, $f->term);
        $answers = ["statement" => "Fictional older submission"];
        new SqlApplicationFormRepository($f->sql)->storeSubmission(
            $id,
            $form,
            $a->id,
            $f->access->actor,
            $f->dropCipher->seal(json_encode($answers, JSON_THROW_ON_ERROR)),
            \Domain\Admissions\ValueObject\SubmissionIntegrity::fingerprint(
                $form,
                $person,
                $f->term,
                $answers,
            ),
        );
        $this->forms($f)->disable($f->program);
        self::assertSame(
            $a->id->value,
            $this->forms($f)->submit($id, $form, $person, $f->term, $answers)->value,
        );
        self::assertSame([], $f->sql->rows("sis_history_events"));
        self::assertNull($f->decisionService()->history($a->id)["submission"]);
        self::assertSame($answers, $this->forms($f)->submission($id)["answers"]);
    }
    public function testADirectRetryCannotClaimAnEarlierUntrackedApplicationIdentity(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $a = $f->legacyApplication($person, $f->program, $f->term);
        $this->expectException(ConcurrentModificationException::class);
        $f->submissionService()->apply($a->id, $person, $f->program, $f->term);
    }
    public function testSubmissionOwnershipAndActorAreRecheckedAfterWaitingForLocks(): void
    {
        $f = new SisFixture();
        $person = $f->admissionsService()->createPerson($f->access->actor, "Fictional", "Applicant");
        $repo = $this->createMock(\Domain\Admissions\Repository\AdmissionsRepository::class);
        $repo->method("person")->willReturn($person);
        $repo
            ->expects(self::once())
            ->method("lockPerson")
            ->willReturnCallback(function () use ($f): void {
                $f->access->actor = $f->user();
                $f->access->staff = false;
            });
        $repo->expects(self::never())->method("addApplication");
        $this->expectException(AccessDeniedException::class);
        $f->submissionService(repository: $repo)->apply(
            EntityId::generate(),
            $person->id,
            $f->program,
            $f->term,
        );
    }
    public function testDecisionReplayCannotAttachDifferentFormAnswersToAKnownSubmission(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $a = $this->forms($f)->submit(EntityId::generate(), $form, $person, $f->term, [
            "statement" => "Fictional original",
        ]);
        $birth = $this->events($f, $a)[0];
        $p = $birth->payload();
        $source = $p["source"];
        $source["submission"]["fingerprint"] = hash("sha256", "Fictional conflicting fingerprint");
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->value));
        $root->acceptHistorical($birth);
        $e = AdmissionWasDecided::occur(
            new AdmissionDecisionId($a->value),
            [
                "decision_id" => EntityId::generate()->value,
                "previous_decision_id" => $p["decision_id"],
                "status" => "accepted",
                "application_version" => 1,
                "source" => $source,
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead(2);
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($e);
    }
    public function testAnApplicantCannotBypassTheirProgramFormUsingTheDirectEntryApi(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $this->form($f);
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $f->submissionService()->apply(EntityId::generate(), $person, $f->program, $f->term);
    }
    public function testDirectEntryPermissionIsRecheckedEvenWhenTheStaffMemberOwnsThePerson(): void
    {
        $f = new SisFixture();
        $person = $f->admissionsService()->createPerson($f->access->actor, "Fictional", "Applicant");
        $repo = $this->createMock(\Domain\Admissions\Repository\AdmissionsRepository::class);
        $repo->method("person")->willReturn($person);
        $repo
            ->expects(self::once())
            ->method("lockPerson")
            ->willReturnCallback(function () use ($f): void {
                $f->access->staff = false;
            });
        $repo->expects(self::never())->method("addApplication");
        $this->expectException(AccessDeniedException::class);
        $f->submissionService(repository: $repo)->apply(
            EntityId::generate(),
            $person->id,
            $f->program,
            $f->term,
        );
    }
    public function testDuplicateSubmissionBindingIsRejectedWithoutExposingDriverDetails(): void
    {
        $f = new SisFixture();
        $person = $this->person($f);
        $form = $this->form($f);
        $id = EntityId::generate();
        $this->forms($f)->submit($id, $form, $person, $f->term, ["statement" => "Fictional original"]);
        $r = $f->sql->one("sis_form_submissions", ["id" => $id->value]);
        $extra = new EntityId("7ZZZZZZZZZZZZZZZZZZZZZZZZZ");
        try {
            new SqlApplicationFormRepository($f->sql)->storeSubmission(
                $extra,
                $form,
                new EntityId($r["application_id"]),
                $f->access->actor,
                $r["encrypted_answers"],
                $r["fingerprint"],
            );
            self::fail("A duplicate application binding was accepted.");
        } catch (ConcurrentModificationException $error) {
            self::assertStringNotContainsString("SQLSTATE", $error->getMessage());
            self::assertCount(1, $f->sql->rows("sis_form_submissions"));
            self::assertSame("Fictional original", $this->forms($f)->submission($id)["answers"]["statement"]);
        }
    }
}
