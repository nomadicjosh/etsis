<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use PHPUnit\Framework\TestCase;

final class TransferAcademicDatesTest extends TestCase
{
    private function fixture(bool $legacy = false): \TransferAwardFixture
    {
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $h = new \HiatusFixture();
        return new \TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }

    private function review(
        \TransferAwardFixture $t,
        string $completed = "2026-05-12",
        int $version = 0,
    ): array {
        return $t
            ->periods()
            ->review(
                $t->student,
                $t->award,
                "2026-01-12",
                $version,
                "Fictional verified transcript dates.",
                $completed,
                "2026-08-10",
            );
    }

    public function testCombinedDatesPreserveOriginalAwardIdentityMoneyAndAcademicFacts(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $tables = ["sis_history_events", "sis_academic_credits", "sis_enrollments"];
        $before = array_map($s->sql->rows(...), $tables);
        $finance = $s->financialEvents();
        $r = $this->review($t);
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $receipt = $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        $award = $s->sql->one("sis_transfer_awards", ["id" => $t->award->value]);
        self::assertSame("2026-05-01", $award["completed_on"]);
        self::assertSame("2026-05-12", $award["corrected_completed_on"]);
        self::assertSame("2026-05-12", $t->repository->get($t->student, $t->award)["completed_on"]);
        self::assertEquals(
            array_replace($before[1][0], ["version" => 1]),
            $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]),
        );
        self::assertSame($before[2], $s->sql->rows("sis_enrollments"));
        self::assertSame($finance, $s->financialEvents());
        foreach ($before[0] as $event) {
            self::assertSame($event, $s->sql->one("sis_history_events", ["id" => $event["id"]]));
        }
        $facts = $s->transferCredits()->auditFacts($t->credit, $t->student);
        self::assertSame("2026-01-12", $facts["academic_on"]);
        self::assertSame("2026-05-12", $facts["completed_on"]);
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $r["review_token"]));
        $history = $t->periods()->history($t->student, $t->award)["items"];
        self::assertSame("2026-05-01", $history[1]["previous_completed_on"]);
        self::assertSame("2026-05-12", $history[1]["completed_on"]);
        self::assertArrayNotHasKey("reason", $history[1]);
        self::assertArrayNotHasKey("actor_id", $history[1]);
    }

    public function testOriginalAwardRetrySurvivesCorrectionAndCorrectedDateCannotCreateDuplicateCredit(): void
    {
        $t = $this->fixture();
        $r = $this->review($t);
        $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        $events = $t->sis->sql->rows("sis_history_events");
        $retry = $t->sis
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
            );
        self::assertSame($t->credit->value, $retry->value);
        try {
            $t->sis
                ->transferCredits()
                ->award(
                    $t->student,
                    $t->equivalency,
                    $t->sis->term,
                    new DateTimeImmutable("2026-05-12"),
                    3,
                    [],
                    $t->sis->access->actor,
                    $t->courseVersion,
                );
            self::fail("A corrected date created another award.");
        } catch (\DomainException) {
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            self::assertCount(1, $t->sis->sql->rows("sis_transfer_awards"));
        }
    }

    public function testOriginalAndCorrectedDatesOfAnotherAwardAreReserved(): void
    {
        $t = $this->fixture();
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $t->sis->sql,
            $t->sis->gradeCatalog(),
        );
        $credit = $t->sis
            ->transferCredits()
            ->award(
                $t->student,
                $t->equivalency,
                $t->sis->term,
                new DateTimeImmutable("2026-05-20"),
                3,
                $catalog->get("equivalencies", $t->equivalency->id),
                $t->sis->access->actor,
                $t->courseVersion,
            );
        $other = new EntityId(
            $t->sis->sql->one("sis_academic_credits", ["id" => $credit->value])["source_id"],
        );
        $review = $t
            ->periods()
            ->review($t->student, $other, null, 0, "Fictional second attempt.", "2026-05-22");
        $t->periods()->commit($t->student, $other, $review["review_token"]);
        foreach (["2026-05-20", "2026-05-22"] as $date) {
            try {
                $this->review($t, $date);
                self::fail("Another award completion date was reused.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLaterCreditChangesAndVoidDoNotChangeCorrectedChronologyOrOriginalDateReceipt(): void
    {
        $t = $this->fixture();
        $r = $this->review($t);
        $receipt = $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        foreach (["amend", "void", "reinstate"] as $action) {
            $change = new AwardChange(
                $action,
                $action === "void" ? null : 3000,
                $action === "void" ? null : "B",
                "Fictional registrar decision.",
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
            self::assertSame("2026-05-12", $t->repository->get($t->student, $t->award)["completed_on"]);
            self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $r["review_token"]));
        }
        $next = $this->review($t, "2026-05-15", 4);
        $t->periods()->commit($t->student, $t->award, $next["review_token"]);
        $events = $t->sis->sql->rows("sis_history_events");
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $r["review_token"]));
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame("2026-05-15", $t->service()->view($t->student, $t->award)["completed_on"]);
    }

    public function testInvalidChronologyCannotAppendEvents(): void
    {
        $t = $this->fixture();
        $before = $t->sis->sql->rows("sis_history_events");
        $cases = [
            ["2026-01-12", "2026-02-30", "2026-08-10"],
            ["2026-01-12", "2026-08-11", "2026-08-10"],
            ["2026-05-13", "2026-05-12", "2026-08-10"],
            ["2026-01-12", "2026-05-12", "2026-05-11"],
            ["2026-01-12", "2026-05-12", "2026-08-11"],
            [null, "2026-05-01", "2026-08-10"],
        ];
        foreach ($cases as [$start, $completed, $effective]) {
            try {
                $t->periods()->review(
                    $t->student,
                    $t->award,
                    $start,
                    0,
                    "Fictional dates.",
                    $completed,
                    $effective,
                );
                self::fail("Invalid chronology accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $t->sis->sql->rows("sis_history_events"));
            }
        }
    }

    public function testCorrectedProjectionDriftIsRejectedBySelectedReads(): void
    {
        $t = $this->fixture();
        $r = $this->review($t);
        $t->periods()->commit($t->student, $t->award, $r["review_token"]);
        $t->sis->sql->update(
            "sis_transfer_awards",
            ["corrected_completed_on" => "2026-05-16"],
            ["id" => $t->award->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $t->service()->view($t->student, $t->award);
    }

    public function testExpiryDuringAuditRollsBackObservationDatesReceiptsAndProjectionTogether(): void
    {
        foreach ([false, true] as $legacy) {
            $t = $this->fixture($legacy);
            $audit = new class ($t) implements AuditTrail {
                public function __construct(private \TransferAwardFixture $t)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($action === "transfer.academic_dates_recorded") {
                        $this->t->sis->clock->time = $this->t->sis->clock->time->modify("+21 minutes");
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $service = $t->periods($audit);
            $r = $service->review($t->student, $t->award, "2026-01-12", 0, "Fictional dates.", "2026-05-12");
            $tables = [
                "sis_history_events",
                "sis_history_operations",
                "sis_academic_credits",
                "sis_transfer_awards",
            ];
            $before = array_map($t->sis->sql->rows(...), $tables);
            try {
                $service->commit($t->student, $t->award, $r["review_token"]);
                self::fail("A review expired during its audit but committed.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, array_map($t->sis->sql->rows(...), $tables));
            }
        }
    }
    public function testAcceptedEarlierPeriodReceiptIsPreservedButPendingEarlierProofCannotWrite(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $data = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional original period.");
        unset($data["review_token"]);
        $data["kind"] = "transfer-period-review";
        $data["effective_at"] = new DateTimeImmutable("2026-01-12")->format(DATE_ATOM);
        $token = $t->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
        $before = $s->sql->rows("sis_history_events");
        try {
            $t->periods()->commit($t->student, $t->award, $token);
            self::fail("An unaccepted old proof started an old-format command.");
        } catch (ValidationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
        $receipt = $s->transactions->run(function () use ($t, $s, $data): array {
            $s->registration->lockStudent($t->student);
            [$root, $facts] = $s
                ->transferCredits()
                ->decisionRecord(
                    $t->student,
                    $t->award,
                    new \Domain\Shared\EventSourcing\HistoryReadBudget(),
                );
            $event = \Domain\Transfer\Event\TransferAcademicPeriodWasRecorded::occur(
                new \Domain\Transfer\ValueObject\TransferCreditId($t->award->value),
                [
                    "decision_id" => $data["operation_id"],
                    "previous_decision_id" => $root->decision(),
                    "previous_academic_on" => null,
                    "academic_on" => "2026-01-12",
                ],
                ["__aggregate_type" => "transfer_credit"],
            )->withPlayhead($root->playhead() + 1);
            $operation = new EntityId($data["operation_id"]);
            $receipt = \EventStoreFixture::history($s->connection->queryBuilder())->append(
                new \Domain\Shared\EventSourcing\StreamReference(
                    new \Domain\Transfer\ValueObject\TransferCreditId($t->award->value),
                    "transfer_credit",
                ),
                $operation,
                $root->playhead(),
                new \Domain\Shared\EventSourcing\EventContext(
                    $s->access->actor,
                    new DateTimeImmutable("2026-01-12"),
                    $operation,
                    new EntityId($root->decision()),
                    $data["reason"],
                ),
                [$event],
            );
            $root->acceptHistorical($event);
            $records = new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository($s->sql);
            $records->project($root->credit(), $facts["version"]);
            $records->track($root->source(), $root->credit());
            return $receipt;
        });
        $next = $this->review($t, version: 1);
        $t->periods()->commit($t->student, $t->award, $next["review_token"]);
        $s->clock->time = $s->clock->time->modify("+1 day");
        $events = $s->sql->rows("sis_history_events");
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $token));
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame("2026-05-12", $t->service()->view($t->student, $t->award)["completed_on"]);
    }
    public function testCompletionCorrectionRequiresFreshTranscriptPreparation(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        $transcript = new \TranscriptFixture();
        $request = $transcript->request();
        $student = new EntityId($transcript->repository->request($request)["student_id"]);
        $t = new \TransferAwardFixture($transcript->sis, $transcript->campus->cipher, $student);
        $renderer = $this->createMock(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->expects(self::never())->method("pdf");
        $service = $transcript->service($renderer);
        $review = $service->review($request);
        $dates = $this->review($t);
        $t->periods()->commit($student, $t->award, $dates["review_token"]);
        try {
            $service->prepare(
                $request,
                new EntityId($review["operation_id"]),
                $review["revision"],
                true,
                $review["review_token"],
            );
            self::fail("A transcript was prepared using superseded transfer chronology.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows("sis_transcript_documents"));
        }
    }
    public function testEarlierAcceptedCreditCorrectionRetryDoesNotUndoLaterDates(): void
    {
        $t = $this->fixture();
        $service = $t->service();
        $change = new AwardChange("amend", 4000, "B", "Fictional earlier correction.", "2026-05-05");
        $review = $service->preview($t->student, $t->award, $change);
        $operation = new EntityId($review["operation_id"]);
        $receipt = $service->confirm(
            $t->student,
            $t->award,
            $operation,
            $change,
            $review["revision"],
            $review["review_token"],
        );
        $dates = $this->review($t, version: 1);
        $t->periods()->commit($t->student, $t->award, $dates["review_token"]);
        $events = $t->sis->sql->rows("sis_history_events");
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        self::assertSame(
            $receipt,
            $service->confirm(
                $t->student,
                $t->award,
                $operation,
                $change,
                $review["revision"],
                $review["review_token"],
            ),
        );
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame("2026-05-12", $t->service()->view($t->student, $t->award)["completed_on"]);
    }
    public function testDateReservedAfterReviewRollsBackTheAttemptedDateDecision(): void
    {
        $t = $this->fixture();
        $review = $this->review($t);
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $t->sis->sql,
            $t->sis->gradeCatalog(),
        );
        $t->sis
            ->transferCredits()
            ->award(
                $t->student,
                $t->equivalency,
                $t->sis->term,
                new DateTimeImmutable("2026-05-12"),
                3,
                $catalog->get("equivalencies", $t->equivalency->id),
                $t->sis->access->actor,
                $t->courseVersion,
            );
        $tables = [
            "sis_history_events",
            "sis_history_operations",
            "sis_transfer_awards",
            "sis_academic_credits",
        ];
        $before = array_map($t->sis->sql->rows(...), $tables);
        try {
            $t->periods()->commit($t->student, $t->award, $review["review_token"]);
            self::fail("A date reserved after review was reused.");
        } catch (ValidationException) {
            self::assertSame($before, array_map($t->sis->sql->rows(...), $tables));
        }
    }
}
