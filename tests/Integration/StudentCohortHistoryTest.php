<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
use Domain\Student\StudentProgram;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use ProgramPublicationFixture;
use StudentRequirementsFixture;
use TranscriptFixture;

final class StudentCohortHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
        require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
    }

    /** @return array{TranscriptFixture,Student,StudentProgram,EntityId,EntityId} */
    private function fixture(): array
    {
        $t = new TranscriptFixture();
        $f = $t->sis;
        $p = new ProgramPublicationFixture($f);
        $first = $p->calendar->year(2024);
        $review = $p->service->review(
            $f->program,
            new EntityId($first["id"]),
            "Fictional original curriculum.",
        );
        $a = new EntityId($p->service->publish($f->program, $review["review_token"])["operation_id"]);
        $person = $f->legacyPerson($f->user(), "Fictional", "Cohort learner");
        $application = $f->legacyApplication(
            $person->id,
            $f->program,
            new EntityId($first["terms"][0]["id"]),
        );
        $f->legacyAdmissionDecision($application->id, true);
        $student = $f->matriculate($application->id);
        $draft = $p->service->draft($f->program);
        $p->service->save(
            $f->program,
            "FUTURE",
            "Fictional revised program",
            "A later curriculum.",
            null,
            null,
            null,
            $draft["revision"],
        );
        $next = $p->calendar->year(2025);
        $review = $p->service->review($f->program, new EntityId($next["id"]), "Fictional later curriculum.");
        $b = new EntityId($p->service->publish($f->program, $review["review_token"])["operation_id"]);
        $assignment = new SqlStudentRecordRepository($f->sql)->assignment(
            new EntityId(
                $f->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0]["id"],
            ),
        );
        return [$t, $student, $assignment, $a, $b];
    }
    /** @return array<string,mixed> */
    private function snapshot(TranscriptFixture $t): array
    {
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_program_status_streams",
            "sis_student_catalogs",
            "sis_student_catalog_changes",
            "sis_student_requirements",
            "sis_academic_credits",
            "sis_students",
        ];
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $t->sis->sql->rows($table, [], 100);
        }
        return $result;
    }
    /** @param array<string,mixed> $review
     * @return array<string,mixed> */
    private function commit(
        TranscriptFixture $t,
        StudentProgram $p,
        EntityId $catalog,
        array $review,
        string $reason = "Fictional approved cohort change.",
    ): array {
        return $t->sis
            ->studentCatalogs()
            ->commit(
                $p->studentId,
                $p->id,
                $catalog,
                new EntityId($review["operation_id"]),
                $review["version"],
                $reason,
                $review["review_token"],
            );
    }

    public function testReviewedCohortChangesShareThePursuitStreamAndPreserveOriginalRetries(): void
    {
        [$t, $student, $p, $a, $b] = $this->fixture();
        $f = $t->sis;
        $service = $f->studentCatalogs();
        $before = $this->snapshot($t);
        $review = $service->review($student->id, $p->id, $b, 0, "Fictional approved cohort change.");
        self::assertSame($before, $this->snapshot($t));
        $result = $this->commit($t, $p, $b, $review);
        self::assertSame(1, $result["binding_version"]);
        self::assertSame("Fictional revised program", $result["catalog"]["program_title"]);
        $root = $f->programStatusLedger()->cohortContext($p);
        self::assertTrue($root["history_recorded"]);
        self::assertFalse($root["earlier_history_unknown"]);
        self::assertSame($b->value, $root["catalog"]["catalog_id"]);
        self::assertSame(
            "Fictional revised program",
            $service->view($student->id, $p->id)["program"]["title"],
        );
        $history = $service->history($student->id, $p->id);
        self::assertCount(1, $history["items"]);
        self::assertSame($a->value, $history["items"][0]["previous_catalog"]["catalog_id"]);
        self::assertArrayNotHasKey("note", $history["items"][0]);
        self::assertArrayNotHasKey("actor_id", $history["items"][0]);
        self::assertCount(1, $history["earlier_receipts"]);
        self::assertSame($before["sis_academic_credits"], $f->sql->rows("sis_academic_credits"));
        self::assertSame($before["sis_students"], $f->sql->rows("sis_students"));
        $back = $service->review($student->id, $p->id, $a, 1, "Fictional approved cohort change.");
        $this->commit($t, $p, $a, $back);
        // A recorded closure fixture exercises archived read/retry contracts, not graduation authoring.
        $f->transactions->run(function () use ($f, $p): void {
            $f->registration->lockStudent($p->studentId);
            $f->programStatusLedger()->change(
                $p,
                "closed",
                EntityId::generate(),
                $f->access->actor,
                "Fictional pursuit closure.",
            );
        });
        $f->clock->time = $f->clock->time->modify("+1 day");
        $after = $this->snapshot($t);
        $closed = new SqlStudentRecordRepository($f->sql)->assignment($p->id);
        self::assertSame($result, $this->commit($t, $closed, $b, $review));
        self::assertSame($a->value, $service->view($student->id, $p->id)["current"]["catalog_id"]);
        self::assertCount(2, $service->history($student->id, $p->id)["items"]);
        self::assertSame($after, $this->snapshot($t));
        $this->expectException(ValidationException::class);
        $service->review($student->id, $p->id, $b, 2, "Fictional approved cohort change.");
    }

    public function testAChangeInvalidatesTranscriptReviewAndRetainsTheOriginalDocument(): void
    {
        [$t, $student, $p, $a, $b] = $this->fixture();
        $f = $t->sis;
        $request = EntityId::generate();
        $actor = $f->access->actor;
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        try {
            $t->campus->service->requestTranscript($request, $student->id, "pickup", "");
        } finally {
            $f->access->actor = $actor;
        }
        $renderer = $this->createStub(TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturn("<p>Fictional protected transcript</p>");
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional cohort document");
        $transcripts = $t->service($renderer);
        $initial = $transcripts->review($request);
        $transcripts->prepare(
            $request,
            new EntityId($initial["operation_id"]),
            $initial["revision"],
            true,
            $initial["review_token"],
        );
        $pending = $transcripts->review($request);
        $cohort = $f
            ->studentCatalogs()
            ->review($student->id, $p->id, $b, 0, "Fictional approved cohort change.");
        $this->commit($t, $p, $b, $cohort);
        $id = new EntityId($initial["operation_id"]);
        self::assertTrue($transcripts->view($request, $id)["academic_changed"]);
        self::assertSame($a->value, $transcripts->view($request, $id)["snapshot"]["catalog"]["id"]);
        self::assertStringContainsString("%PDF-", $transcripts->pdf($request, $id));
        $current = $transcripts->review($request)["snapshot"];
        self::assertSame($b->value, $current["catalog"]["id"]);
        self::assertSame("Fictional revised program", $current["program"]["title"]);
        self::assertTrue($current["program_verification"]["cohort_history_recorded"]);
        $this->expectException(ConcurrentModificationException::class);
        $transcripts->prepare(
            $request,
            new EntityId($pending["operation_id"]),
            $pending["revision"],
            true,
            $pending["review_token"],
        );
    }

    public function testEarlierReceiptsStayIntactAndOlderFactsAreObservedOnlyWithARealChange(): void
    {
        [$t, , , $a, $b] = $this->fixture();
        $f = $t->sis;
        $student = $f->student();
        $p = new SqlStudentRecordRepository($f->sql)->assignment(
            new EntityId(
                $f->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0]["id"],
            ),
        );
        $catalogs = new SqlCatalogYearRepository($f->sql);
        $catalogs->bind($p->id, $a, -1);
        $old = EntityId::generate();
        $catalogs->record(
            $old,
            $p->id,
            $f->access->actor,
            null,
            $a,
            $f->dropCipher->seal(
                json_encode(["version" => -1, "note" => "Fictional older receipt."], JSON_THROW_ON_ERROR),
            ),
            $f->clock->now()->format(DATE_ATOM),
        );
        $receipt = $catalogs->action($old);
        $before = $this->snapshot($t);
        $review = $f
            ->studentCatalogs()
            ->review($p->studentId, $p->id, $b, 0, "Fictional approved cohort change.");
        self::assertTrue($review["earlier_history_unknown"]);
        self::assertSame($before, $this->snapshot($t));
        $this->commit($t, $p, $b, $review);
        self::assertSame($receipt, $catalogs->action($old));
        self::assertCount(count($before["sis_history_events"]) + 2, $f->sql->rows("sis_history_events"));
        $page = $f->studentCatalogs()->history($p->studentId, $p->id);
        self::assertCount(1, $page["earlier_receipts"]);
        self::assertTrue($page["items"][0]["earlier_history_unknown"]);
        self::assertStringNotContainsString(
            "Fictional older receipt.",
            json_encode($page, JSON_THROW_ON_ERROR),
        );
    }

    public function testStaleExpiredAndAlteredConfirmationsCannotChangeTheCohort(): void
    {
        $cases = ["expiry", "stale", "reason", "actor", "permission", "token"];
        foreach ($cases as $case) {
            [$t, , $p, $a, $b] = $this->fixture();
            $f = $t->sis;
            $review = $f
                ->studentCatalogs()
                ->review($p->studentId, $p->id, $b, 0, "Fictional approved cohort change.");
            $reason = "Fictional approved cohort change.";
            if ($case === "expiry") {
                $f->clock->time = $f->clock->time->modify("+20 minutes");
            }
            if ($case === "stale") {
                $other = $f->studentCatalogs()->review($p->studentId, $p->id, $b, 0, $reason);
                $this->commit($t, $p, $b, $other);
            }
            if ($case === "reason") {
                $reason = "An unreviewed reason.";
            }
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "permission") {
                $f->access->staff = false;
            }
            if ($case === "token") {
                $review["review_token"] .= "damaged";
            }
            $before = $this->snapshot($t);
            try {
                $this->commit($t, $p, $b, $review, $reason);
                self::fail("A changed or expired confirmation was accepted.");
            } catch (ConcurrentModificationException | AccessDeniedException | ValidationException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testAuditFailureAuthorityChangeAndExpiryRollBackTheWholeDecision(): void
    {
        $cases = ["audit", "actor", "permission", "expiry"];
        foreach ($cases as $case) {
            [$t, , $p, , $b] = $this->fixture();
            $f = $t->sis;
            $review = $f
                ->studentCatalogs()
                ->review($p->studentId, $p->id, $b, 0, "Fictional approved cohort change.");
            $before = $this->snapshot($t);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(static function () use ($f, $case): void {
                if ($case === "audit") {
                    throw new \RuntimeException("Fictional required audit failure.");
                }
                if ($case === "actor") {
                    $f->access->actor = $f->user();
                }
                if ($case === "permission") {
                    $f->access->staff = false;
                }
                if ($case === "expiry") {
                    $f->clock->time = $f->clock->time->modify("+20 minutes");
                }
            });
            try {
                $f->studentCatalogs($audit)->commit(
                    $p->studentId,
                    $p->id,
                    $b,
                    new EntityId($review["operation_id"]),
                    0,
                    "Fictional approved cohort change.",
                    $review["review_token"],
                );
                self::fail("A cohort decision escaped its required completion checks.");
            } catch (\RuntimeException | ConcurrentModificationException | AccessDeniedException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testRecordedCohortDriftCannotSupplyCurriculumOrTranscriptInputs(): void
    {
        $cases = ["binding", "version", "missing", "labels", "unpublished", "journal"];
        foreach ($cases as $case) {
            [$t, , $p, $a, $b] = $this->fixture();
            $f = $t->sis;
            $review = $f
                ->studentCatalogs()
                ->review($p->studentId, $p->id, $b, 0, "Fictional approved cohort change.");
            $this->commit($t, $p, $b, $review);
            if ($case === "binding") {
                $f->sql->update(
                    "sis_student_catalogs",
                    ["catalog_id" => $a->value],
                    ["assignment_id" => $p->id->value],
                );
            }
            if ($case === "version") {
                $f->sql->update(
                    "sis_student_catalogs",
                    ["version" => 98],
                    ["assignment_id" => $p->id->value],
                );
            }
            if ($case === "missing") {
                $f->sql->execute(
                    "DELETE FROM " .
                        $f->sql->quote("sis_student_catalogs") .
                        " WHERE " .
                        $f->sql->quote("assignment_id") .
                        " = ?",
                    [$p->id->value],
                );
            }
            if ($case === "labels") {
                $f->sql->update(
                    "sis_program_catalogs",
                    ["program_title" => "Unrecorded overwrite"],
                    ["id" => $b->value],
                );
            }
            if ($case === "unpublished") {
                $f->sql->update("sis_program_catalogs", ["published" => 0], ["id" => $b->value]);
            }
            if ($case === "journal") {
                $head = $f->sql->one("sis_history_streams", [
                    "stream_type" => "student_program_status",
                    "aggregate_id" => $p->id->value,
                ]);
                $f->sql->update(
                    "sis_history_events",
                    ["event_hash" => str_repeat("0", 64)],
                    ["stream_id" => $head["id"]],
                );
            }
            $before = $this->snapshot($t);
            try {
                $f->studentCatalogs()->view($p->studentId, $p->id);
                self::fail("Unverified cohort projection was exposed.");
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testSelectedRequirementsStayRetainedAndCohortDriftBlocksTheirRead(): void
    {
        $r = new StudentRequirementsFixture();
        $r->assign();
        $f = $r->f->sis;
        $catalogs = new SqlCatalogYearRepository($f->sql);
        $old = $catalogs->binding($r->assignment);
        $release = $catalogs->release(new EntityId($old["catalog_id"]));
        $next = EntityId::generate();
        $catalogs->addRelease($next, [
            "program_id" => $release["program_id"],
            "year_id" => $release["year_id"],
            "revision" => $release["revision"] + 1,
            "published" => 1,
            "is_current" => 0,
            "program_code" => $release["program_code"],
            "program_title" => $release["program_title"],
        ]);
        $selection = $f->sql->rows("sis_student_requirements");
        $review = $f
            ->studentCatalogs()
            ->review($r->student, $r->assignment, $next, $old["version"], "Fictional curriculum revision.");
        $f->studentCatalogs()->commit(
            $r->student,
            $r->assignment,
            $next,
            new EntityId($review["operation_id"]),
            $old["version"],
            "Fictional curriculum revision.",
            $review["review_token"],
        );
        $view = $r->service->view($r->student, $r->assignment);
        self::assertTrue($view["cohort_changed"]);
        self::assertSame($selection, $f->sql->rows("sis_student_requirements"));
        self::assertSame($r->version->value, $view["selection"]["requirements_version_id"]);
        $f->sql->update(
            "sis_student_catalogs",
            ["catalog_id" => $release["id"]],
            ["assignment_id" => $r->assignment->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $r->service->view($r->student, $r->assignment);
    }

    public function testCohortReadsShareTheExistingReplayBudget(): void
    {
        [$t, , $p, , $b] = $this->fixture();
        $review = $t->sis
            ->studentCatalogs()
            ->review($p->studentId, $p->id, $b, 0, "Fictional approved cohort change.");
        $this->commit($t, $p, $b, $review);
        $this->expectException(HistoryReadLimitException::class);
        $t->sis->programStatusLedger()->cohortContext($p, new HistoryReadBudget(1, 1));
    }

    public function testPursuitAndBindingVersionsAreBothRequiredAtAppend(): void
    {
        [$t, , $p, , $b] = $this->fixture();
        $f = $t->sis;
        $target = \Domain\Student\Service\StudentProgramStatusLedger::catalogSnapshot(
            new SqlCatalogYearRepository($f->sql)->release($b),
        );
        $before = $this->snapshot($t);
        $cases = [[99, 0], [1, 99]];
        foreach ($cases as [$stream, $binding]) {
            try {
                $f->transactions->run(function () use ($f, $p, $target, $stream, $binding): void {
                    $f->registration->lockStudent($p->studentId);
                    $f->programStatusLedger()->changeCohort(
                        $p,
                        $target,
                        EntityId::generate(),
                        $f->access->actor,
                        "Fictional stale append.",
                        $stream,
                        $binding,
                    );
                });
                self::fail("A stale expected version reached cohort append.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testHistoryPagesStayFrozenAcrossLaterCohortDecisions(): void
    {
        [$t, , $p, $a, $b] = $this->fixture();
        $service = $t->sis->studentCatalogs();
        $last = null;
        for ($version = 0; $version < 12; $version++) {
            $target = $version % 2 === 0 ? $b : $a;
            $review = $service->review(
                $p->studentId,
                $p->id,
                $target,
                $version,
                "Fictional approved cohort change.",
            );
            $last = $this->commit($t, $p, $target, $review);
        }
        $page = $service->history($p->studentId, $p->id);
        self::assertCount(9, $page["items"]); // The same page also contains the original assignment.
        self::assertNotNull($page["next_cursor"]);
        $review = $service->review($p->studentId, $p->id, $b, 12, "Fictional approved cohort change.");
        $this->commit($t, $p, $b, $review);
        $before = $this->snapshot($t);
        $rest = $service->history($p->studentId, $p->id, $page["next_cursor"]);
        self::assertCount(3, $rest["items"]);
        self::assertNull($rest["next_cursor"]);
        self::assertSame($before, $this->snapshot($t));
        self::assertSame(12, $last["binding_version"]);
    }

    public function testPrivateCohortReadsRejectForeignPursuitsAndAuthorityChanges(): void
    {
        [$t, , $p] = $this->fixture();
        $f = $t->sis;
        $other = $f->student();
        try {
            $f->studentCatalogs()->view($other->id, $p->id);
            self::fail("A foreign pursuit was exposed.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($f): void {
            $f->access->staff = false;
        });
        $this->expectException(AccessDeniedException::class);
        $f->studentCatalogs($audit)->history($p->studentId, $p->id);
    }
}
