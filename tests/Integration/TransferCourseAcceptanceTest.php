<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use PHPUnit\Framework\TestCase;

final class TransferCourseAcceptanceTest extends TestCase
{
    private function fixture(bool $legacy = false): TransferAwardFixture
    {
        $h = new HiatusFixture();
        return new TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }
    private function publish(TransferAwardFixture $t, string $title = "Reviewed algebra"): EntityId
    {
        $t->sis->sql->update(
            "sis_courses",
            ["title" => $title, "credits_milli" => 6000],
            ["id" => $t->course->value],
        );
        return $t->sis->publishCourse($t->course);
    }
    private function review(TransferAwardFixture $t, EntityId $publication): array
    {
        $v = $t->service()->view($t->student, $t->award);
        return $t
            ->courses()
            ->review(
                $t->student,
                $t->award,
                $publication,
                $v["credit"]["version"],
                "2026-08-01",
                "Fictional accepted equivalency evidence.",
            );
    }
    private function correction(TransferAwardFixture $t, string $kind): void
    {
        $c = new AwardChange(
            $kind,
            $kind === "void" ? null : 3125,
            $kind === "void" ? null : "B",
            "Fictional credit decision.",
        );
        $r = $t->service()->preview($t->student, $t->award, $c);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $c, $r["revision"]);
    }
    public function testAcceptancePreservesOriginalFactsFinancialHistoryAndIndependentAwardedUnits(): void
    {
        $t = $this->fixture();
        $later = $this->publish($t);
        $events = $t->sis->sql->rows("sis_history_events");
        $awards = $t->sis->sql->rows("sis_transfer_awards");
        $finance = $t->sis->financialEvents();
        $before = $t->service()->view($t->student, $t->award);
        $r = $this->review($t, $later);
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame("Computing", $r["before"]["course_title"]);
        $receipt = $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        $view = $t->service()->view($t->student, $t->award);
        self::assertSame($later->value, $view["course_version_id"]);
        self::assertSame("Reviewed algebra", $view["credit"]["course_title"]);
        self::assertSame(3000, $view["credit"]["completed_milli"]);
        self::assertSame($before["credit"]["grade_id"], $view["credit"]["grade_id"]);
        self::assertSame(1, $view["credit"]["version"]);
        self::assertSame($awards, $t->sis->sql->rows("sis_transfer_awards"));
        self::assertSame($finance, $t->sis->financialEvents());
        foreach ($events as $e) {
            self::assertSame($e, $t->sis->sql->one("sis_history_events", ["id" => $e["id"]]));
        }
        self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $r["review_token"]));
        $h = $t->courses()->history($t->student, $t->award);
        self::assertSame(["award", "course_accepted"], array_column($h["items"], "action"));
        self::assertSame(["Computing", "Reviewed algebra"], array_column($h["items"], "course_title"));
        self::assertSame($t->courseVersion->value, $h["items"][1]["previous_course_version_id"]);
        self::assertStringStartsWith("2026-08-01", $h["items"][1]["effective_at"]);
        self::assertStringNotContainsString(
            "Fictional accepted equivalency evidence",
            json_encode($h, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ["award", "course_accepted"],
            array_column($t->periods()->history($t->student, $t->award)["items"], "action"),
        );
    }
    public function testUnknownEarlierAwardIsObservedOnlyAtConfirmationAndDoesNotInventItsOriginalPublication(): void
    {
        $t = $this->fixture(true);
        $pub = $this->publish($t);
        $events = $t->sis->sql->rows("sis_history_events");
        $r = $this->review($t, $pub);
        self::assertTrue($r["earlier_history_unknown"]);
        self::assertNull($r["previous_course_version_id"]);
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        $receipt = $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        $v = $t->service()->view($t->student, $t->award);
        self::assertTrue($v["earlier_history_unknown"]);
        self::assertSame($pub->value, $v["course_version_id"]);
        $h = $t->courses()->history($t->student, $t->award)["items"];
        self::assertSame(["observed_existing", "course_accepted"], array_column($h, "action"));
        self::assertNull($h[0]["course_version_id"]);
        self::assertStringStartsWith("2026-08-10", $h[0]["effective_at"]);
        self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $r["review_token"]));
    }
    public function testLaterDecisionsAndExpiryDoNotLetAnAcceptedRetryUndoANewerCourseOrVoid(): void
    {
        $t = $this->fixture();
        $p1 = $this->publish($t);
        $r1 = $this->review($t, $p1);
        $receipt = $t->courses()->commit($t->student, $t->award, $r1["review_token"]);
        $period = $t->periods()->review($t->student, $t->award, "2026-01-12", 1, "Fictional external term.");
        $t->periods()->commit($t->student, $t->award, $period["review_token"]);
        $p2 = $this->publish($t, "Second accepted algebra");
        $r2 = $this->review($t, $p2);
        $t->courses()->commit($t->student, $t->award, $r2["review_token"]);
        $this->correction($t, "amend");
        $this->correction($t, "void");
        $events = $t->sis->sql->rows("sis_history_events");
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $r1["review_token"]));
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        $this->correction($t, "reinstate");
        $v = $t->service()->view($t->student, $t->award);
        self::assertSame($p2->value, $v["course_version_id"]);
        self::assertSame("Second accepted algebra", $v["credit"]["course_title"]);
        self::assertSame("2026-01-12", $v["academic_on"]);
    }
    public function testStaleExpiredWrongActorAndForeignTokensCannotCreateAnyDecision(): void
    {
        foreach (["stale", "expiry", "actor", "student", "award", "forged"] as $mode) {
            $t = $this->fixture(true);
            $p = $this->publish($t);
            $r = $this->review($t, $p);
            if ($mode === "stale") {
                $t->sis->sql->update(
                    "sis_academic_credits",
                    ["course_title" => "Changed untracked fact"],
                    ["id" => $t->credit->value],
                );
            }
            if ($mode === "expiry") {
                $t->sis->clock->time = $t->sis->clock->time->modify("+21 minutes");
            }
            if ($mode === "actor") {
                $t->sis->access->actor = EntityId::generate();
            }
            $events = $t->sis->sql->rows("sis_history_events");
            $receipts = $t->sis->sql->rows("sis_history_operations");
            try {
                $t->courses()->commit(
                    $mode === "student" ? EntityId::generate() : $t->student,
                    $mode === "award" ? EntityId::generate() : $t->award,
                    $mode === "forged" ? "invalid" : $r["review_token"],
                );
                self::fail("A stale or foreign acceptance committed.");
            } catch (ConcurrentModificationException | AccessDeniedException | ValidationException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
                self::assertSame($receipts, $t->sis->sql->rows("sis_history_operations"));
                self::assertNull(
                    $t->sis->sql->rows("sis_transfer_credit_streams", ["id" => $t->award->value], 1)[0] ??
                        null,
                );
            }
        }
    }
    public function testOnlyTransferEvaluatorsCanAccessEveryReadAndWriteAndCannotCrossStudents(): void
    {
        $t = $this->fixture();
        $p = $this->publish($t);
        $r = $this->review($t, $p);
        $otherStudent = $t->sis->student()->id;
        $t->sis->access->staff = false;
        foreach ([["sis:transfer:manage"], ["sis:student:read"], []] as $grants) {
            $t->sis->access->grants = $grants;
            foreach (
                [
                    fn() => $t->courses()->choices($t->student, $t->award),
                    fn() => $t->courses()->history($t->student, $t->award),
                    fn() => $this->review($t, $p),
                    fn() => $t->courses()->commit($t->student, $t->award, $r["review_token"]),
                ]
                as $call
            ) {
                try {
                    $call();
                    self::fail("Partial authority accepted.");
                } catch (AccessDeniedException) {
                    self::assertTrue(true);
                }
            }
        }
        $t->sis->access->grants = ["sis:transfer:manage", "sis:student:read"];
        self::assertCount(2, $t->courses()->choices($t->student, $t->award)["items"]);
        $this->expectException(AccessDeniedException::class);
        $t->courses()->choices($otherStudent, $t->award);
    }
    public function testInvalidChoicesDatesReasonsAndVoidedRecordsRequireFreshCorrectReview(): void
    {
        $t = $this->fixture();
        $foreign = $t->sis->publishCourse($t->sis->section()->courseId);
        foreach (
            [
                [$foreign, "2026-08-01", "Fictional evidence"],
                [$t->courseVersion, "2026-08-01", "Fictional evidence"],
                [$this->publish($t), "2026-04-30", "Fictional evidence"],
                [$this->publish($t), "2026-08-11", "Fictional evidence"],
                [$this->publish($t), "2026-08-01", " "],
            ]
            as [$version, $date, $reason]
        ) {
            $events = $t->sis->sql->rows("sis_history_events");
            try {
                $t->courses()->review($t->student, $t->award, $version, 0, $date, $reason);
                self::fail("Invalid review accepted.");
            } catch (ValidationException | \Domain\Shared\Exception\RecordNotFoundException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            }
        }
        $this->correction($t, "void");
        $this->expectException(ValidationException::class);
        $this->review($t, $this->publish($t));
    }
    public function testAuditFailureAndRevocationRollBackObservationEventReferenceAndLabels(): void
    {
        foreach (["throw", "revoke"] as $mode) {
            $t = $this->fixture(true);
            $pub = $this->publish($t);
            $r = $this->review($t, $pub);
            $tables = [
                "sis_history_events",
                "sis_history_operations",
                "sis_transfer_credit_streams",
                "sis_academic_credits",
                "sis_credit_course_versions",
                "sis_course_versions",
                "sis_course_version_classes",
                "sis_course_version_subjects",
            ];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $t->sis->sql->rows($table);
            }
            $audit = new class ($t, $mode) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(private TransferAwardFixture $t, private string $mode) {}
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->mode === "throw") {
                        throw new RuntimeException("Fictional unavailable audit.");
                    }
                    $this->t->sis->access->staff = false;
                    $this->t->sis->access->grants = [];
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            try {
                $t->courses($audit)->commit($t->student, $t->award, $r["review_token"]);
                self::fail("Failed decision committed.");
            } catch (RuntimeException | AccessDeniedException) {
                foreach ($before as $table => $rows) {
                    self::assertSame($rows, $t->sis->sql->rows($table));
                }
            }
        }
    }
    public function testAcceptedLabelCorrectionPreservesPreparedDocumentButRequiresFreshPreparationForDelivery(): void
    {
        $documents = new TranscriptFixture();
        $request = $documents->request();
        $student = new EntityId($documents->repository->request($request)["student_id"]);
        $t = new TransferAwardFixture($documents->sis, $documents->campus->cipher, $student);
        $renderer = $this->createStub(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional retained document.");
        $service = $documents->service($renderer);
        $preparation = $service->review($request);
        $id = new EntityId($preparation["operation_id"]);
        $service->prepare($request, $id, $preparation["revision"], true);
        $before = $documents->repository->document($id);
        $publication = $this->publish($t, "Corrected accepted transcript title");
        $review = $this->review($t, $publication);
        $t->courses()->commit($student, $t->award, $review["review_token"]);
        self::assertSame($before, $documents->repository->document($id));
        self::assertTrue($service->view($request, $id)["academic_changed"]);
        try {
            $service->deliver(
                $request,
                $id,
                EntityId::generate(),
                1,
                $t->sis->clock->now(),
                "Fictional stale dispatch.",
            );
            self::fail("A stale prepared transcript was delivered after a course acceptance correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows("sis_transcript_deliveries"));
        }
        $fresh = $service->review($request);
        $replacement = new EntityId($fresh["operation_id"]);
        $service->prepare($request, $replacement, $fresh["revision"], true);
        self::assertStringContainsString(
            "Corrected accepted transcript title",
            json_encode($service->view($request, $replacement)["snapshot"], JSON_THROW_ON_ERROR),
        );
        self::assertSame($before, $documents->repository->document($id));
    }
    public function testKnownReferenceCorruptionRejectsTheAwardRatherThanRepairingOnRead(): void
    {
        $t = $this->fixture();
        $p = $this->publish($t);
        $r = $this->review($t, $p);
        $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        $t->sis->sql->update(
            "sis_credit_course_versions",
            ["course_version_id" => $t->courseVersion->value],
            ["id" => $t->credit->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $t->courses()->commit($t->student, $t->award, $r["review_token"]);
    }
}
