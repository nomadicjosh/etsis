<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransferCourseReplacementTest extends TestCase
{
    private function fixture(bool $legacy = false): \TransferAwardFixture
    {
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $h = new \HiatusFixture();
        return new \TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }

    private function target(\TransferAwardFixture $t): array
    {
        $course = $t->sis->section(20, 0)->courseId;
        $t->sis->sql->update(
            "sis_courses",
            ["code" => "ENG.220", "title" => "Fictional literature", "credits_milli" => 4000],
            ["id" => $course->value],
        );
        return [$course, $t->sis->publishCourse($course)];
    }

    private function review(
        \TransferAwardFixture $t,
        EntityId $course,
        EntityId $publication,
        int $version = 0,
    ): array {
        return $t
            ->courses()
            ->review(
                $t->student,
                $t->award,
                $publication,
                $version,
                "2026-08-10",
                "Fictional corrected receiving-course evaluation.",
                $course,
            );
    }

    private function snapshot(\TransferAwardFixture $t): array
    {
        return array_map($t->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_transfer_awards",
            "sis_transfer_credit_streams",
            "sis_academic_credits",
            "sis_credit_course_versions",
            "sis_work_pursuit_scopes",
            "sis_student_programs",
        ]);
    }

    public function testReplacementKeepsOriginalAwardEquivalencyQuantitiesPursuitAndMoney(): void
    {
        foreach ([false, true] as $legacy) {
            $t = $this->fixture($legacy);
            [$course, $publication] = $this->target($t);
            $s = $t->sis;
            $credit = $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]);
            $awards = $s->sql->rows("sis_transfer_awards");
            $pursuits = $s->sql->rows("sis_work_pursuit_scopes");
            $equivalencies = $s->sql->rows("sis_transfer_equivalencies");
            $finance = $s->financialEvents();
            $events = $s->sql->rows("sis_history_events");
            $r = $this->review($t, $course, $publication);
            self::assertTrue($r["replacement"]);
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            $t->courses()->commit($t->student, $t->award, $r["review_token"]);
            self::assertEquals(
                array_replace($credit, [
                    "course_id" => $course->value,
                    "course_code" => "ENG.220",
                    "course_title" => "Fictional literature",
                    "version" => 1,
                ]),
                $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]),
            );
            self::assertSame($awards, $s->sql->rows("sis_transfer_awards"));
            self::assertSame($pursuits, $s->sql->rows("sis_work_pursuit_scopes"));
            self::assertSame($equivalencies, $s->sql->rows("sis_transfer_equivalencies"));
            self::assertSame($finance, $s->financialEvents());
            foreach ($events as $event) {
                self::assertSame($event, $s->sql->one("sis_history_events", ["id" => $event["id"]]));
            }
            $view = $t->service()->view($t->student, $t->award);
            self::assertSame($course->value, $view["course_id"]);
            self::assertSame($publication->value, $view["course_version_id"]);
            self::assertSame(3000, $view["credit"]["completed_milli"]);
            $history = $t->courses()->history($t->student, $t->award);
            self::assertSame("course_replaced", $history["items"][1]["action"]);
            self::assertSame($credit["course_code"], $history["items"][1]["previous_course_code"]);
            self::assertStringNotContainsString(
                "Fictional corrected receiving-course evaluation",
                json_encode($history, JSON_THROW_ON_ERROR),
            );
            self::assertArrayNotHasKey("actor_id", $history["items"][1]);
            self::assertTrue(
                $t->periods()->history($t->student, $t->award)["items"][1]["action"] === "course_replaced",
            );
        }
    }

    public function testOriginalAwardAndCourseAcceptanceRetriesCannotUndoReplacement(): void
    {
        $t = $this->fixture();
        $earlierPublication = $t->sis->publishCourse($t->course);
        $early = $t
            ->courses()
            ->review(
                $t->student,
                $t->award,
                $earlierPublication,
                0,
                "2026-08-10",
                "Fictional earlier acceptance.",
            );
        $receipt = $t->courses()->commit($t->student, $t->award, $early["review_token"]);
        [$course, $publication] = $this->target($t);
        $replacement = $this->review($t, $course, $publication, 1);
        $t->courses()->commit($t->student, $t->award, $replacement["review_token"]);
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        $before = $this->snapshot($t);
        self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $early["review_token"]));
        self::assertSame(
            $t->credit->value,
            $t->sis
                ->transferCredits()
                ->award(
                    $t->student,
                    $t->equivalency,
                    $t->sis->term,
                    new DateTimeImmutable("2026-05-01"),
                    3,
                    [],
                    $t->sis->access->actor,
                    $t->courseVersion,
                )->value,
        );
        self::assertSame($before, $this->snapshot($t));
        self::assertSame($course->value, $t->service()->view($t->student, $t->award)["credit"]["course_id"]);
    }

    public function testGradeVoidReinstateAndDateChangesKeepReceivingCourseAndOriginalReceipt(): void
    {
        $t = $this->fixture();
        [$course, $publication] = $this->target($t);
        $r = $this->review($t, $course, $publication);
        $receipt = $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        foreach (["amend", "void", "reinstate"] as $action) {
            $change = new AwardChange(
                $action,
                $action === "void" ? null : 3000,
                $action === "void" ? null : "B",
                "Fictional award amendment.",
            );
            $review = $t->service()->preview($t->student, $t->award, $change);
            $t->service()->confirm(
                $t->student,
                $t->award,
                new EntityId($review["operation_id"]),
                $change,
                $review["revision"],
                $review["review_token"],
            );
            self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $r["review_token"]));
            self::assertSame(
                $course->value,
                $t->service()->view($t->student, $t->award)["credit"]["course_id"],
            );
        }
        $dates = $t
            ->periods()
            ->review($t->student, $t->award, "2026-01-12", 4, "Fictional source correction.");
        $t->periods()->commit($t->student, $t->award, $dates["review_token"]);
        $next = $t->sis->publishCourse($course);
        $review = $t
            ->courses()
            ->review($t->student, $t->award, $next, 5, "2026-08-10", "Fictional same-course acceptance.");
        self::assertFalse($review["replacement"]);
        $t->courses()->commit($t->student, $t->award, $review["review_token"]);
        self::assertSame($receipt, $t->courses()->commit($t->student, $t->award, $r["review_token"]));
    }

    public function testTargetMustBeAnActualPublicationOfTheExplicitlySelectedCourse(): void
    {
        $t = $this->fixture();
        [$course, $publication] = $this->target($t);
        $before = $this->snapshot($t);
        $cases = [[$t->course, $publication], [$course, $t->courseVersion], [$course, EntityId::generate()]];
        foreach ($cases as [$target, $version]) {
            try {
                $this->review($t, $target, $version);
                self::fail("A publication from another course or an unknown publication was accepted.");
            } catch (RecordNotFoundException | ValidationException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testPendingReplacementCannotOverwriteAnInterveningAcademicDecision(): void
    {
        $t = $this->fixture();
        [$course, $publication] = $this->target($t);
        $r = $this->review($t, $course, $publication);
        $date = $t
            ->periods()
            ->review($t->student, $t->award, "2026-01-12", 0, "Fictional intervening decision.");
        $t->periods()->commit($t->student, $t->award, $date["review_token"]);
        $before = $this->snapshot($t);
        try {
            $t->courses()->commit($t->student, $t->award, $r["review_token"]);
            self::fail("A stale replacement overwrote a newer decision.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($t));
        }
    }

    public function testFailureRollsBackObservedFactsReplacementProjectionAndReceiptTogether(): void
    {
        foreach (["audit", "expire", "authority", "actor"] as $mode) {
            $t = $this->fixture(true);
            [$course, $publication] = $this->target($t);
            $audit = new class ($t, $mode) implements AuditTrail {
                public function __construct(private \TransferAwardFixture $t, private string $mode)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($action !== "transfer.course_replaced") {
                        return;
                    }
                    if ($this->mode === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    if ($this->mode === "expire") {
                        $this->t->sis->clock->time = $this->t->sis->clock->time->modify("+21 minutes");
                    }
                    if ($this->mode === "authority") {
                        $this->t->sis->access->staff = false;
                        $this->t->sis->access->grants = [];
                    }
                    if ($this->mode === "actor") {
                        $this->t->sis->access->actor = $this->t->sis->user();
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $service = $t->courses($audit);
            $r = $service->review(
                $t->student,
                $t->award,
                $publication,
                0,
                "2026-08-10",
                "Fictional replacement.",
                $course,
            );
            $before = $this->snapshot($t);
            try {
                $service->commit($t->student, $t->award, $r["review_token"]);
                self::fail("A failed replacement committed partial academic facts.");
            } catch (RuntimeException | ConcurrentModificationException | AccessDeniedException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testSearchIsBoundedLiteralReadOnlyAndRequiresBothPermissionsAndAwardScope(): void
    {
        $t = $this->fixture();
        for ($i = 0; $i < 24; $i++) {
            $t->sis->section(20, 0);
        }
        $before = $this->snapshot($t);
        self::assertSame([], $t->courses()->search($t->student, $t->award, "")["items"]);
        $first = $t->courses()->search($t->student, $t->award, "CS-");
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_after"]);
        $second = $t->courses()->search($t->student, $t->award, "CS-", new EntityId($first["next_after"]));
        self::assertCount(5, $second["items"]);
        self::assertSame([], $t->courses()->search($t->student, $t->award, "%")["items"]);
        self::assertSame($before, $this->snapshot($t));
        $t->sis->access->staff = false;
        foreach ([["sis:transfer:manage"], ["sis:student:read"]] as $grants) {
            $t->sis->access->grants = $grants;
            try {
                $t->courses()->search($t->student, $t->award, "CS-");
                self::fail("Missing scope was accepted.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $t->sis->access->staff = true;
        $this->expectException(AccessDeniedException::class);
        $t->courses()->search($t->sis->student()->id, $t->award, "CS-");
    }

    public function testVersionIndexAndCourseIdentityDriftAreRejectedOnSelectedReads(): void
    {
        $t = $this->fixture();
        [$course, $publication] = $this->target($t);
        $r = $this->review($t, $course, $publication);
        $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        $t->sis->sql->update(
            "sis_academic_credits",
            ["course_id" => $t->course->value],
            ["id" => $t->credit->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $t->service()->view($t->student, $t->award);
    }
    public function testReplacementFeedsVerifiedDegreeInputsAndLeavesPursuitAttributionIntact(): void
    {
        $t = $this->fixture();
        [$course, $publication] = $this->target($t);
        $scope = $t->sis->sql->rows("sis_work_pursuit_scopes");
        $r = $this->review($t, $course, $publication);
        $t->courses()->commit($t->student, $t->award, $r["review_token"]);
        $s = $t->sis;
        $before = $this->snapshot($t);
        $s->transactions->run(function () use ($s, $t, $course, $publication): void {
            $s->registration->lockStudent($t->student);
            $input = new \Domain\DegreeAudit\Service\AcademicInputVerifier(
                new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($s->sql),
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger(),
            )->verify($t->student, new HistoryReadBudget());
            self::assertCount(1, $input->items);
            self::assertSame($course->value, $input->items[0]["course_id"]);
            self::assertSame($publication->value, $input->items[0]["course_version_id"]);
            self::assertSame(3000, $input->items[0]["completed_milli"]);
        });
        self::assertSame($scope, $s->sql->rows("sis_work_pursuit_scopes"));
        self::assertSame($before, $this->snapshot($t));
    }

    public function testReplacementRequiresFreshTranscriptPreparation(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $transcript = new \TranscriptFixture();
        $request = $transcript->request();
        $student = new EntityId($transcript->repository->request($request)["student_id"]);
        $t = new \TransferAwardFixture($transcript->sis, $transcript->campus->cipher, $student);
        [$course, $publication] = $this->target($t);
        $renderer = $this->createMock(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method("pdf");
        $service = $transcript->service($renderer);
        $review = $service->review($request);
        $replacement = $this->review($t, $course, $publication);
        $t->courses()->commit($student, $t->award, $replacement["review_token"]);
        try {
            $service->prepare(
                $request,
                new EntityId($review["operation_id"]),
                $review["revision"],
                true,
                $review["review_token"],
            );
            self::fail("An outdated course was used for transcript preparation.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
        }
    }
}
