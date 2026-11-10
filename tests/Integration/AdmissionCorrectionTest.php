<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Event\AdmissionDecisionWasCorrected;
use Domain\Admissions\Event\AdmissionDecisionWasObserved;
use Domain\Admissions\ValueObject\AdmissionCorrectionSchema;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AdmissionCorrectionTest extends TestCase
{
    private function application(
        SisFixture $f,
        bool $accept = true,
        bool $legacy = false,
    ): AdmissionApplication {
        $person = $f->admissionsService()->createPerson($f->user(), "Fictional", "Applicant");
        if ($legacy) {
            $a = $f->legacyApplication($person->id, $f->program, $f->term);
            $f->legacyAdmissionDecision($a->id, $accept);
        } else {
            $a = $f->submissionService()->apply(EntityId::generate(), $person->id, $f->program, $f->term);
            $review = $f->decisionService()->review($a->id, $accept, "Fictional initial decision");
            $f->decisionService()->commit($a->id, $review["review_token"]);
        }
        return $f->admissions->application($a->id);
    }
    private function correct(SisFixture $f, EntityId $id, bool $accept): array
    {
        $s = $f->correctionService();
        $r = $s->review($id, $accept, "Fictional protected correction reason");
        return $s->commit($id, $r["review_token"]);
    }
    private function events(SisFixture $f, EntityId $id): array
    {
        return iterator_to_array(
            EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
                new AdmissionDecisionId($id->value),
            ),
        );
    }
    public function testBothCorrectionsAreReviewedWithoutWritesAndPreserveTheOriginalEventBytes(): void
    {
        foreach ([true, false] as $initial) {
            $f = new SisFixture();
            $a = $this->application($f, $initial);
            $s = $f->correctionService();
            $before = $f->sql->rows("sis_history_events");
            $r = $s->review($a->id, !$initial, "Fictional protected correction reason");
            self::assertSame($initial ? "accepted" : "rejected", $r["before"]["status"]);
            self::assertTrue($r["before"]["history_recorded"]);
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            $result = $s->commit($a->id, $r["review_token"]);
            self::assertSame($initial ? "rejected" : "accepted", $result["status"]);
            self::assertSame(2, $result["version"]);
            foreach ($before as $row) {
                self::assertSame($row, $f->sql->one("sis_history_events", ["id" => $row["id"]]));
            }
            $events = $this->events($f, $a->id);
            self::assertCount(3, $events);
            self::assertInstanceOf(AdmissionDecisionWasCorrected::class, $events[2]);
            $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
            foreach ($events as $event) {
                $root->acceptHistorical($event);
            }
            self::assertSame($result["status"], $root->application()["status"]);
            self::assertSame(2, $root->application()["version"]);
            $history = $f->decisionService()->history($a->id);
            self::assertSame(
                ["submission", "decision", "correction"],
                array_column($history["items"], "kind"),
            );
            self::assertSame($result, $history["decision"]);
            self::assertStringNotContainsString(
                "Fictional protected correction reason",
                json_encode([$history, $f->sql->rows("sis_history_events")], JSON_THROW_ON_ERROR),
            );
        }
    }
    public function testOlderAcceptedAndRejectedStatesAreObservedWithoutInventingEarlierDecisionDates(): void
    {
        foreach ([true, false] as $initial) {
            $f = new SisFixture();
            $a = $this->application($f, $initial, true);
            $s = $f->correctionService();
            $r = $s->review($a->id, !$initial, "Fictional correction of an earlier outcome");
            self::assertFalse($r["before"]["history_recorded"]);
            self::assertSame([], $f->sql->rows("sis_history_events"));
            $s->commit($a->id, $r["review_token"]);
            $events = $this->events($f, $a->id);
            self::assertCount(2, $events);
            self::assertInstanceOf(AdmissionDecisionWasObserved::class, $events[0]);
            self::assertSame($a->status->value, $events[0]->payload()["application"]["status"]);
            $history = $f->decisionService()->history($a->id);
            self::assertTrue($history["earlier_history_unknown"]);
            self::assertNull($history["submission"]);
            self::assertNull($history["items"][0]["effective_at"]);
            self::assertSame(["observation", "correction"], array_column($history["items"], "kind"));
        }
    }
    public function testExactInitialAndCorrectionRetriesReturnEarlierResultsAfterLaterCorrectionsAndMatriculation(): void
    {
        $f = new SisFixture();
        $person = $f->admissionsService()->createPerson($f->user(), "Fictional", "Applicant");
        $a = $f->submissionService()->apply(EntityId::generate(), $person->id, $f->program, $f->term);
        $initial = $f->decisionService()->review($a->id, true, "Fictional first acceptance");
        $original = $f->decisionService()->commit($a->id, $initial["review_token"]);
        $correction = $f->correctionService()->review($a->id, false, "Fictional rejection correction");
        $rejection = $f->correctionService()->commit($a->id, $correction["review_token"]);
        $this->correct($f, $a->id, true);
        $student = $f->matriculate($a->id);
        $journal = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($original, $f->decisionService()->commit($a->id, $initial["review_token"]));
        self::assertSame($rejection, $f->correctionService()->commit($a->id, $correction["review_token"]));
        self::assertSame("matriculated", $f->admissions->application($a->id)->status->value);
        self::assertSame($journal, $f->sql->rows("sis_history_events"));
        self::assertSame($student->id->value, $f->sql->rows("sis_students")[0]["id"]);
    }
    public function testStaleReviewIsRejectedAfterAnAcceptedRejectedAcceptedCycle(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $r = $f->correctionService()->review($a->id, false, "Fictional earlier review");
        $this->correct($f, $a->id, false);
        $this->correct($f, $a->id, true);
        $this->expectException(ConcurrentModificationException::class);
        $f->correctionService()->commit($a->id, $r["review_token"]);
    }
    public function testMatriculationWinningTheRaceBlocksAnUncommittedCorrection(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $r = $f->correctionService()->review($a->id, false, "Fictional correction");
        $f->matriculate($a->id);
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->correctionService()->commit($a->id, $r["review_token"]);
            self::fail("Matriculation was reversed.");
        } catch (ValidationException) {
            self::assertSame("matriculated", $f->admissions->application($a->id)->status->value);
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }
    public function testCorrectionWinningTheRaceBlocksAnEarlierMatriculationReview(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $r = $f->matriculationService()->review($a->id, "Fictional matriculation");
        $this->correct($f, $a->id, false);
        try {
            $f->matriculationService()->commit($a->id, $r["review_token"]);
            self::fail("A rejected application matriculated.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_students"));
        }
    }
    public function testSubmittedSameOutcomeAndMatriculatedApplicationsCannotStartCorrections(): void
    {
        foreach (["same", "submitted", "matriculated"] as $case) {
            $f = new SisFixture();
            $a = $this->application($f);
            if ($case === "submitted") {
                $a = $f->legacyApplication($a->personId, $f->program, $f->term);
            } elseif ($case === "matriculated") {
                $f->matriculate($a->id);
            }
            $before = $f->sql->rows("sis_history_events");
            try {
                $f->correctionService()->review($a->id, $case === "same", "Fictional invalid correction");
                self::fail("Invalid correction accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testAdmissionsManagementAndCorrectionAuthorityAreIndependentlyRequired(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->staff = false;
        $grantSets = [
            ["sis:admissions:manage"],
            ["sis:admissions:correct"],
            ["sis:student:read", "sis:registration:manage"],
        ];
        foreach ($grantSets as $grants) {
            $f->access->grants = $grants;
            try {
                $f->correctionService()->review($a->id, false, "Fictional forbidden correction");
                self::fail("Incomplete authority accepted.");
            } catch (AccessDeniedException) {
                self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
            }
        }
        $f->access->grants = ["sis:admissions:manage", "sis:admissions:correct"];
        self::assertSame("rejected", $this->correct($f, $a->id, false)["status"]);
    }
    public function testPermissionRevokedAfterWaitingForTheApplicationLockBlocksTheCorrection(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $f->access->staff = false;
        $f->access->grants = ["sis:admissions:manage", "sis:admissions:correct"];
        $repo = $this->createMock(\Domain\Admissions\Repository\AdmissionsRepository::class);
        $repo->method("application")->willReturn($a);
        $repo
            ->expects(self::once())
            ->method("lockPerson")
            ->willReturnCallback(function () use ($f): void {
                $f->access->grants = ["sis:admissions:manage"];
            });
        $repo->expects(self::once())->method("lockApplication");
        $repo->expects(self::never())->method("saveCorrection");
        $this->expectException(AccessDeniedException::class);
        $f->correctionService(repository: $repo)->review($a->id, false, "Fictional correction");
    }
    public function testChangedIdentityLabelsOrFormContextRequireAnotherReview(): void
    {
        foreach (["person", "program", "term"] as $change) {
            $f = new SisFixture();
            $a = $this->application($f);
            $s = $f->correctionService();
            $r = $s->review($a->id, false, "Fictional correction");
            match ($change) {
                "person" => $f->admissionsService()->updateInstitutionalId($a->personId, "TEST-CHANGED", 0),
                "program" => $f->sql->update(
                    "sis_programs",
                    ["title" => "Changed fictional title"],
                    ["id" => $f->program->value],
                ),
                "term" => $f->sql->update(
                    "sis_terms",
                    ["code" => "CHANGED.TERM"],
                    ["id" => $f->term->value],
                ),
            };
            try {
                $s->commit($a->id, $r["review_token"]);
                self::fail("Changed context was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
            }
        }
    }
    public function testReviewExpiryAndAnInstitutionalDateChangeRequireANewReview(): void
    {
        foreach (["+21 minutes", "+1 day"] as $interval) {
            $f = new SisFixture();
            $a = $this->application($f);
            $r = $f->correctionService()->review($a->id, false, "Fictional correction");
            $f->clock->time = $f->clock->time->modify($interval);
            try {
                $f->correctionService()->commit($a->id, $r["review_token"]);
                self::fail("Expired correction accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
            }
        }
    }
    public function testTokenPurposeActorApplicationAndCurrentPermissionsAreBound(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $other = $this->application($f);
        $r = $f->correctionService()->review($a->id, false, "Fictional correction");
        $initialToken = $f->dropCipher->seal('{"purpose":"admission-decision.v1"}');
        foreach (["actor", "application", "purpose", "damaged", "permission"] as $case) {
            $actor = $f->access->actor;
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "permission") {
                $f->access->staff = false;
                $f->access->grants = ["sis:admissions:manage"];
            }
            $token = $case === "purpose" ? $initialToken : $r["review_token"];
            if ($case === "damaged") {
                $token .= "!";
            }
            try {
                $f->correctionService()->commit($case === "application" ? $other->id : $a->id, $token);
                self::fail("Invalid authority accepted.");
            } catch (ValidationException | AccessDeniedException) {
                self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
            } finally {
                $f->access->actor = $actor;
                $f->access->staff = true;
                $f->access->grants = [];
            }
        }
    }
    public function testAuditFailureRollsBackBothLegacyObservationAndActualCorrection(): void
    {
        foreach ([false, true] as $legacy) {
            $f = new SisFixture();
            $a = $this->application($f, true, $legacy);
            $r = $f->correctionService()->review($a->id, false, "Fictional correction");
            $tables = [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_applications",
            ];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $f->sql->rows($table);
            }
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
            try {
                $f->correctionService($audit)->commit($a->id, $r["review_token"]);
                self::fail("Partial correction committed.");
            } catch (RuntimeException $error) {
                self::assertSame("Fictional audit failure", $error->getMessage());
            }
            foreach ($tables as $table) {
                self::assertSame($before[$table], $f->sql->rows($table));
            }
            self::assertSame(
                "rejected",
                $f->correctionService()->commit($a->id, $r["review_token"])["status"],
            );
        }
    }
    public function testHistoryPagesKeepTheirCommittedHeadAcrossLaterCorrections(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        for ($i = 0; $i < 12; ++$i) {
            $this->correct($f, $a->id, $i % 2 === 1);
        }
        $first = $f->decisionService()->history($a->id);
        self::assertCount(10, $first["items"]);
        self::assertNotNull($first["next_cursor"]);
        $this->correct($f, $a->id, false);
        $second = $f->decisionService()->history($a->id, $first["next_cursor"]);
        self::assertCount(4, $second["items"]);
        self::assertNull($second["next_cursor"]);
        self::assertSame("rejected", $second["current_status"]);
        self::assertSame("accepted", $second["items"][3]["status"]);
        $fresh = $f->decisionService()->history($a->id);
        $freshSecond = $f->decisionService()->history($a->id, $fresh["next_cursor"]);
        self::assertCount(5, $freshSecond["items"]);
    }
    public function testAConflictingCurrentProjectionStopsCorrectionAndHistory(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $this->correct($f, $a->id, false);
        $f->sql->update("sis_applications", ["status" => "accepted"], ["id" => $a->id->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->correctionService()->review($a->id, false, "Fictional correction");
    }
    public function testReasonsAreRequiredAndLongUnicodeReasonsRemainProtected(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        foreach (["", "bad\x00reason", "\xFF", str_repeat("a", 10001)] as $reason) {
            try {
                $f->correctionService()->review($a->id, false, $reason);
                self::fail("Invalid reason accepted.");
            } catch (ValidationException) {
                self::assertSame("accepted", $f->admissions->application($a->id)->status->value);
            }
        }
        $r = $f->correctionService()->review($a->id, false, str_repeat("学", 10000));
        self::assertSame("rejected", $f->correctionService()->commit($a->id, $r["review_token"])["status"]);
    }
    public function testFrozenCorrectionSchemaRejectsUnknownFieldsNoOpsAndRepeatedDecisions(): void
    {
        $f = new SisFixture();
        $a = $this->application($f);
        $this->correct($f, $a->id, false);
        $events = $this->events($f, $a->id);
        $last = $events[2];
        $p = $last->payload();
        $invalid = [
            $p + ["extra" => true],
            array_replace($p, ["previous_status" => "submitted"]),
            array_replace($p, ["status" => $p["previous_status"]]),
            array_replace($p, ["application_version" => true]),
        ];
        foreach ($invalid as $payload) {
            try {
                AdmissionCorrectionSchema::validate(AdmissionDecisionWasCorrected::class, $payload);
                self::fail("Invalid correction event accepted.");
            } catch (CorruptHistoryException | InvalidArgumentException $error) {
                self::assertNotSame("", $error->getMessage());
            }
        }
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        foreach ($events as $event) {
            $root->acceptHistorical($event);
        }
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($last->withPlayhead(4));
    }
}
