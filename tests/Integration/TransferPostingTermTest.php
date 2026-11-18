<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransferPostingTermTest extends TestCase
{
    private function fixture(bool $legacy = false): \TransferAwardFixture
    {
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $h = new \HiatusFixture();
        return new \TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }

    private function term(\TransferAwardFixture $t, string $code = "27/SP"): EntityId
    {
        $term = EntityId::generate();
        $t->sis->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => $code,
            "starts_on" => "2027-01-04",
            "ends_on" => "2027-05-20",
            "add_drop_ends_on" => "2027-01-15",
        ]);
        return $term;
    }

    private function review(
        \TransferAwardFixture $t,
        EntityId $term,
        int $version = 0,
        bool $dates = false,
    ): array {
        return $t
            ->periods()
            ->review(
                $t->student,
                $t->award,
                $dates ? "2026-01-12" : null,
                $version,
                "Fictional placement evidence.",
                $dates ? "2026-05-12" : "2026-05-01",
                "2026-08-10",
                $term,
            );
    }

    public function testOneReviewMovesPostingAndOptionalDatesWithoutChangingAwardQuantitiesOrIdentity(): void
    {
        foreach ([false, true] as $legacy) {
            foreach ([false, true] as $dates) {
                $t = $this->fixture($legacy);
                $s = $t->sis;
                $term = $this->term($t);
                $beforeCredit = $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]);
                $events = $s->sql->rows("sis_history_events");
                $finance = $s->financialEvents();
                $pursuitTables = ["sis_student_programs", "sis_work_pursuit_scopes"];
                $pursuits = array_map($s->sql->rows(...), $pursuitTables);
                $review = $this->review($t, $term, dates: $dates);
                self::assertSame($events, $s->sql->rows("sis_history_events"));
                self::assertSame($s->term->value, $review["previous_term_id"]);
                $t->periods()->commit($t->student, $t->award, $review["review_token"]);
                self::assertEquals(
                    array_replace($beforeCredit, ["term_id" => $term->value, "version" => 1]),
                    $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]),
                );
                $row = $s->sql->one("sis_transfer_awards", ["id" => $t->award->value]);
                self::assertSame($term->value, $row["term_id"]);
                self::assertSame("2026-05-01", $row["completed_on"]);
                self::assertSame($finance, $s->financialEvents());
                self::assertSame($pursuits, array_map($s->sql->rows(...), $pursuitTables));
                foreach ($events as $event) {
                    self::assertSame($event, $s->sql->one("sis_history_events", ["id" => $event["id"]]));
                }
                $facts = $s->transferCredits()->auditFacts($t->credit, $t->student);
                self::assertSame($term->value, $facts["credit"]["term_id"]);
                self::assertSame("27/SP", $facts["retained_term_code"]);
                self::assertSame($dates ? "2026-01-12" : null, $facts["academic_on"]);
                self::assertSame($dates ? "2026-05-12" : "2026-05-01", $facts["completed_on"]);
                $history = $t->periods()->history($t->student, $t->award);
                $last = $history["items"][array_key_last($history["items"])];
                self::assertSame("posting_term_changed", $last["action"]);
                self::assertSame("27/SP", $last["term_code"]);
                self::assertArrayNotHasKey("actor_id", $last);
                self::assertStringNotContainsString(
                    "Fictional placement evidence",
                    json_encode($history, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    public function testOriginalAwardRetryRetainsItsOpeningTermAfterPostingCorrection(): void
    {
        $t = $this->fixture();
        $term = $this->term($t);
        $review = $this->review($t, $term);
        $t->periods()->commit($t->student, $t->award, $review["review_token"]);
        $events = $t->sis->sql->rows("sis_history_events");
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
        try {
            $t->sis
                ->transferCredits()
                ->award(
                    $t->student,
                    $t->equivalency,
                    $term,
                    new DateTimeImmutable("2026-05-01"),
                    3,
                    [],
                    $t->sis->access->actor,
                    $t->courseVersion,
                );
            self::fail("A different original request replaced award placement.");
        } catch (\DomainException) {
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        }
    }

    public function testRetainedTermLabelsAndAcceptedRetrySurviveLaterTermSetupAndDateChanges(): void
    {
        $t = $this->fixture();
        $term = $this->term($t);
        $r = $this->review($t, $term);
        $receipt = $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        $t->sis->sql->update("sis_terms", ["code" => "NEW.SP"], ["id" => $term->value]);
        self::assertSame("27/SP", $t->service()->view($t->student, $t->award)["term_code"]);
        $next = $t->periods()->review($t->student, $t->award, "2026-01-12", 1, "Fictional source dates.");
        $t->periods()->commit($t->student, $t->award, $next["review_token"]);
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        $events = $t->sis->sql->rows("sis_history_events");
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $r["review_token"]));
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame("27/SP", $t->service()->view($t->student, $t->award)["term_code"]);
    }

    public function testAnInterveningTermDefinitionChangeRequiresFreshReview(): void
    {
        $t = $this->fixture();
        $term = $this->term($t);
        $r = $this->review($t, $term);
        $t->sis->sql->update("sis_terms", ["starts_on" => "2027-01-05"], ["id" => $term->value]);
        $before = $this->snapshot($t);
        try {
            $t->periods()->commit($t->student, $t->award, $r["review_token"]);
            self::fail("Changed target-term setup was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($t));
        }
    }

    public function testInterveningPostingChangeInvalidatesPendingDateAndCourseReviews(): void
    {
        $t = $this->fixture();
        $dates = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional pending dates.");
        $publication = $t->sis->publishCourse($t->course);
        $course = $t
            ->courses()
            ->review($t->student, $t->award, $publication, 0, "2026-08-10", "Fictional pending acceptance.");
        $r = $this->review($t, $this->term($t));
        $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        foreach ([[$t->periods(), $dates], [$t->courses(), $course]] as [$service, $review]) {
            $before = $this->snapshot($t);
            try {
                $service->commit($t->student, $t->award, $review["review_token"]);
                self::fail("An earlier pending review replaced newer posting facts.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testVoidReinstateAndGradeCorrectionsKeepTheCorrectedTermAndOriginalDateReceipt(): void
    {
        $t = $this->fixture();
        $dates = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional earlier dates.");
        // Fictional proof issued by the earlier date-only editor, before term binding existed.
        $earlier = json_decode($t->cipher->open($dates["review_token"]), true, flags: JSON_THROW_ON_ERROR);
        unset(
            $earlier["previous_term_id"],
            $earlier["term_id"],
            $earlier["previous_term_code"],
            $earlier["term_code"],
            $earlier["term_revision"]
        );
        $dates["review_token"] = $t->cipher->seal(json_encode($earlier, JSON_THROW_ON_ERROR));
        $receipt = $t->periods()->commit($t->student, $t->award, $dates["review_token"]);
        $term = $this->term($t);
        $r = $t
            ->periods()
            ->review($t->student, $t->award, "2026-01-12", 1, "Fictional placement.", term: $term);
        $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        foreach (["amend", "void", "reinstate"] as $action) {
            $change = new AwardChange(
                $action,
                $action === "void" ? null : 3000,
                $action === "void" ? null : "B",
                "Fictional award decision.",
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
            self::assertSame($term->value, $t->service()->view($t->student, $t->award)["term_id"]);
            self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $dates["review_token"]));
        }
    }

    public function testAtomicFailureNeverLeavesPartialObservationPlacementDatesOrReceipt(): void
    {
        foreach (["audit", "expire", "revoke", "actor", "term"] as $mode) {
            $t = $this->fixture(true);
            $term = $this->term($t);
            $audit = new class ($t, $term, $mode) implements AuditTrail {
                public function __construct(
                    private \TransferAwardFixture $t,
                    private EntityId $term,
                    private string $mode,
                ) {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($action !== "transfer.posting_term_changed") {
                        return;
                    }
                    if ($this->mode === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    if ($this->mode === "expire") {
                        $this->t->sis->clock->time = $this->t->sis->clock->time->modify("+21 minutes");
                    }
                    if ($this->mode === "revoke") {
                        $this->t->sis->access->staff = false;
                        $this->t->sis->access->grants = [];
                    }
                    if ($this->mode === "actor") {
                        $this->t->sis->access->actor = $this->t->sis->user();
                    }
                    if ($this->mode === "term") {
                        $this->t->sis->sql->update(
                            "sis_terms",
                            ["code" => "CHANGED"],
                            ["id" => $this->term->value],
                        );
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $service = $t->periods($audit);
            $r = $service->review(
                $t->student,
                $t->award,
                "2026-01-12",
                0,
                "Fictional combined evidence.",
                "2026-05-12",
                term: $term,
            );
            $before = $this->snapshot($t);
            try {
                $service->commit($t->student, $t->award, $r["review_token"]);
                self::fail("A failed confirmation committed partial transfer facts.");
            } catch (RuntimeException | ConcurrentModificationException | AccessDeniedException) {
                self::assertSame($before, $this->snapshot($t));
            }
        }
    }

    public function testBothAwardAndCreditTermProjectionDriftAreRejected(): void
    {
        foreach (["sis_transfer_awards", "both"] as $mode) {
            $t = $this->fixture();
            $term = $this->term($t);
            $t->sis->sql->update(
                "sis_transfer_awards",
                ["term_id" => $term->value],
                ["id" => $t->award->value],
            );
            if ($mode === "both") {
                $t->sis->sql->update(
                    "sis_academic_credits",
                    ["term_id" => $term->value],
                    ["id" => $t->credit->value],
                );
            }
            try {
                $t->sis->transferCredits()->auditFacts($t->credit, $t->student, new HistoryReadBudget());
                self::fail("Posting projection drift escaped stream verification.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    private function snapshot(\TransferAwardFixture $t): array
    {
        return array_map($t->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_transfer_awards",
            "sis_academic_credits",
            "sis_terms",
            "sis_credit_course_versions",
        ]);
    }
    public function testCorrectedPostingFeedsVerifiedDegreeInputsWithoutChangingThePursuit(): void
    {
        $t = $this->fixture();
        $term = $this->term($t);
        $r = $this->review($t, $term);
        $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        $s = $t->sis;
        $before = $this->snapshot($t);
        $s->transactions->run(function () use ($t, $s, $term): void {
            $s->registration->lockStudent($t->student);
            $input = new \Domain\DegreeAudit\Service\AcademicInputVerifier(
                new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($s->sql),
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger(),
            )->verify($t->student, new HistoryReadBudget());
            self::assertCount(1, $input->items);
            self::assertSame($term->value, $input->items[0]["term_id"]);
            self::assertSame("27/SP", $input->items[0]["term_code"]);
            $scope = $s->sql->one("sis_work_pursuit_scopes", ["id" => $t->credit->value]);
            self::assertNotNull($scope);
        });
        self::assertSame($before, $this->snapshot($t));
    }

    public function testPostingCorrectionRequiresFreshTranscriptPreparation(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $transcript = new \TranscriptFixture();
        $request = $transcript->request();
        $student = new EntityId($transcript->repository->request($request)["student_id"]);
        $t = new \TransferAwardFixture($transcript->sis, $transcript->campus->cipher, $student);
        $renderer = $this->createMock(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method("pdf");
        $service = $transcript->service($renderer);
        $review = $service->review($request);
        $posting = $this->review($t, $this->term($t));
        $t->periods()->commit($student, $t->award, $posting["review_token"]);
        try {
            $service->prepare(
                $request,
                new EntityId($review["operation_id"]),
                $review["revision"],
                true,
                $review["review_token"],
            );
            self::fail("A stale posting-term review was used for transcript preparation.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
        }
    }
    public function testConnectedCourseAcceptanceAlsoRechecksExpiryThroughItsRequiredAudit(): void
    {
        $t = $this->fixture(true);
        $version = $t->sis->publishCourse($t->course);
        $audit = new class ($t) implements AuditTrail {
            public function __construct(private \TransferAwardFixture $t)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === "transfer.course_accepted") {
                    $this->t->sis->clock->time = $this->t->sis->clock->time->modify("+21 minutes");
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $t->courses($audit);
        $review = $service->review(
            $t->student,
            $t->award,
            $version,
            0,
            "2026-08-10",
            "Fictional accepted course.",
        );
        $before = $this->snapshot($t);
        try {
            $service->commit($t->student, $t->award, $review["review_token"]);
            self::fail("The connected acceptance review expired during confirmation.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($t));
        }
    }
}
