<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\TransferCredit;
use Domain\Transfer\ValueObject\AwardChange;
use Domain\Transfer\ValueObject\TransferCreditId;
use Infrastructure\Persistence\Repository\SqlTransferCreditRepository;
use PHPUnit\Framework\TestCase;
final class TransferCreditHistoryTest extends TestCase
{
    private function fixture(bool $legacy = false): TransferAwardFixture
    {
        $h = new HiatusFixture();
        return new TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }
    private function replay(TransferAwardFixture $t): TransferCredit
    {
        $root = TransferCredit::root(new TransferCreditId($t->award->value));
        $cases1 = EventStoreFixture::framework($t->sis->connection->queryBuilder())->getAggregateHistoryFor(
            new TransferCreditId($t->award->value),
        );
        foreach ($cases1 as $e) {
            $root->acceptHistorical($e);
        }
        return $root;
    }
    private function post(TransferAwardFixture $t, AwardChange $change, ?EntityId $operation = null): array
    {
        $review = $t->service()->preview($t->student, $t->award, $change);
        $operation ??= EntityId::generate();
        return [
            $t->service()->confirm($t->student, $t->award, $operation, $change, $review["revision"]),
            $review,
            $operation,
        ];
    }
    public function testAwardAndChangesReplayExactFactsAndRetainTheReviewedSource(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $original = $this->replay($t);
        self::assertSame("award", $original->provenance());
        self::assertSame(3, $original->source()["grade_rank"]);
        self::assertSame(2, $original->source()["equivalency"]["minimum_grade_rank"]);
        $events = $f->sql->rows("sis_history_events");
        $finance = $f->financialEvents();
        $this->post(
            $t,
            new AwardChange(
                "amend",
                3125,
                "B",
                "Fictional documented assessment.",
                effectiveOn: "2026-08-01",
            ),
        );
        $root = $this->replay($t);
        self::assertEquals(new SqlTransferCreditRepository($f->sql)->credit($t->credit), $root->credit());
        self::assertSame(9375, $root->credit()["grade_points_milli"]);
        self::assertSame($events, array_slice($f->sql->rows("sis_history_events"), 0, count($events)));
        self::assertSame($finance, $f->financialEvents());
        $f->sql->update(
            "sis_external_institutions",
            ["name" => "New institution label"],
            ["id" => $t->equivalency->externalInstitutionId->value],
        );
        $f->sql->update(
            "sis_external_courses",
            ["title" => "New external title"],
            ["id" => $t->equivalency->externalCourseId->value],
        );
        $f->sql->update("sis_courses", ["title" => "New institutional title"], ["id" => $t->course->value]);
        $view = $t->service()->view($t->student, $t->award);
        self::assertSame("Fictional Sending College", $view["institution_name"]);
        self::assertSame("Fictional algebra", $view["external_title"]);
        self::assertTrue($view["history_recorded"]);
        self::assertFalse($view["earlier_history_unknown"]);
        $history = $t->service()->history($t->student, $t->award)["items"][0];
        self::assertSame("journal", $history["history_source"]);
        self::assertStringStartsWith("2026-08-01", $history["effective_at"]);
    }
    public function testVoidRetainsPublishedGradingPolicyAcrossLaterPublicationAndReinstatement(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $grades = [
            [
                "code" => "TR",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ];
        $first = $f->gradeScale($grades);
        $t = new TransferAwardFixture($f, $h->cipher);
        $this->post($t, new AwardChange("void", null, null, "Duplicate award reviewed."));
        $grades[1]["points_milli"] = 5000;
        $f->gradeScale($grades);
        $this->post($t, new AwardChange("reinstate", 3000, "B", "Reinstatement approved."));
        $root = $this->replay($t);
        self::assertSame(9000, $root->credit()["grade_points_milli"]);
        self::assertSame($first["operation_id"], $root->grade()["scale_version_id"]);
        self::assertSame(3, $root->playhead());
    }
    public function testRetriesAfterLaterChangesPreserveCurrentFactsAndProtectedReceipts(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $change = new AwardChange("amend", 4000, "B", "Private original correction.");
        [$receipt, $review, $operation] = $this->post($t, $change);
        $this->post($t, new AwardChange("void", null, null, "Voided after review."));
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_transfer_award_changes");
        self::assertSame(
            $receipt,
            $t->service()->confirm($t->student, $t->award, $operation, $change, $review["revision"]),
        );
        self::assertSame("V", $this->replay($t)->credit()["status"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_transfer_award_changes"));
        $payload = json_decode(
            $t->cipher->open($receipts[0]["encrypted_content"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame("transfer-receipt.v2", $payload["format"]);
        self::assertArrayNotHasKey("before", $payload);
        self::assertArrayNotHasKey("after", $payload);
        self::assertArrayNotHasKey("request", $payload);
        self::assertStringNotContainsString("Private original correction", json_encode([$events, $receipts]));
        $this->expectException(ConcurrentModificationException::class);
        $t->service()->confirm(
            $t->student,
            $t->award,
            $operation,
            new AwardChange("amend", 4000, "B", $change->reason, effectiveOn: "2026-08-01"),
            $review["revision"],
        );
    }
    public function testObservedExistingFactsPreserveEarlierReceiptBytesAndDoNotInventAnAward(): void
    {
        $t = $this->fixture(true);
        $f = $t->sis;
        $before = $t->repository->get($t->student, $t->award)["credit"] + [
            "grade_code" => "TR",
            "counts_gpa" => false,
        ];
        $after = array_replace($before, [
            "attempted_milli" => 3500,
            "completed_milli" => 3500,
            "version" => 1,
        ]);
        $f->sql->update(
            "sis_academic_credits",
            array_intersect_key($after, array_flip(["attempted_milli", "completed_milli", "version"])),
            ["id" => $t->credit->value],
        );
        $old = EntityId::generate();
        $change = new AwardChange("amend", 3500, "TR", "Earlier fictional review.");
        $request = $change->describe();
        unset($request["effective_on"]);
        $revision = str_repeat("a", 64);
        $t->repository->record(
            $old,
            $t->award,
            $t->credit,
            $f->access->actor,
            1,
            "amend",
            $t->cipher->seal(
                json_encode(
                    ["request" => $request, "revision" => $revision, "before" => $before, "after" => $after],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            "2026-07-01T17:00:00+00:00",
        );
        $receipt = $t->repository->action($old);
        $events = $f->sql->rows("sis_history_events");
        $new = new AwardChange("amend", 4000, "B", "Current reviewed correction.", effectiveOn: "2026-07-15");
        $review = $t->service()->preview($t->student, $t->award, $new);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertTrue($review["record"]["earlier_history_unknown"]);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $new, $review["revision"]);
        self::assertSame($receipt, $t->repository->action($old));
        $root = $this->replay($t);
        self::assertSame("observed_existing", $root->provenance());
        self::assertNull($root->source()["equivalency"]);
        self::assertNull($root->source()["grade_rank"]);
        self::assertSame(2, $root->playhead());
        $items = $t->service()->history($t->student, $t->award)["items"];
        self::assertSame(["journal", "legacy_receipt"], array_column($items, "history_source"));
        self::assertSame($before, $items[1]["before"]);
        self::assertNull($items[1]["effective_at"]);
        $count = count($f->sql->rows("sis_history_events"));
        $t->service()->confirm($t->student, $t->award, $old, $change, $revision);
        self::assertCount($count, $f->sql->rows("sis_history_events"));
    }
    public function testObservationAndChangeRollbackTogetherWhenAuditFails(): void
    {
        $t = $this->fixture(true);
        $f = $t->sis;
        $change = new AwardChange("amend", 4000, "B", "Fictional interrupted observation.");
        $review = $t->service()->preview($t->student, $t->award, $change);
        $events = $f->sql->rows("sis_history_events");
        $receipts = $f->sql->rows("sis_history_operations");
        $before = $f->sql->one("sis_academic_credits", ["id" => $t->credit->value]);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure."));
        try {
            $t->service($audit)->confirm(
                $t->student,
                $t->award,
                EntityId::generate(),
                $change,
                $review["revision"],
            );
            self::fail("Partial baseline committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure.", $e->getMessage());
        }
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($receipts, $f->sql->rows("sis_history_operations"));
        self::assertSame($before, $f->sql->one("sis_academic_credits", ["id" => $t->credit->value]));
        self::assertNull(new SqlTransferCreditRepository($f->sql)->mapping($t->award));
        self::assertSame([], $f->sql->rows("sis_transfer_award_changes"));
    }
    public function testChangedProjectionOrMissingTrackingMarkerFailsClosed(): void
    {
        foreach (["projection", "marker"] as $kind) {
            $t = $this->fixture();
            $f = $t->sis;
            if ($kind === "projection") {
                $f->sql->update(
                    "sis_academic_credits",
                    ["completed_milli" => 2000],
                    ["id" => $t->credit->value],
                );
            } else {
                $f->sql->execute("DELETE FROM sis_transfer_credit_streams WHERE id = ?", [$t->award->value]);
            }
            try {
                $t->service()->view($t->student, $t->award);
                self::fail("Broken tracked history was accepted as old state.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
    public function testEffectiveDatesAreBoundedAndInvalidInputsDoNotWriteHistory(): void
    {
        $t = $this->fixture();
        $events = $t->sis->sql->rows("sis_history_events");
        foreach (["2026-04-30", "2026-08-11", "2026-02-30"] as $date) {
            try {
                $t->service()->preview(
                    $t->student,
                    $t->award,
                    new AwardChange("amend", 4000, "B", "Fictional invalid date.", $date),
                );
                self::fail("Invalid effective date accepted.");
            } catch (ValidationException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            }
        }
    }
    public function testProjectionCanBeRebuiltInAnIsolatedDatabaseWithoutHistoryOrFinancialWrites(): void
    {
        $t = $this->fixture();
        $this->post($t, new AwardChange("amend", 4125, "B", "Fictional rebuild sample."));
        $root = $this->replay($t);
        $events = $t->sis->sql->rows("sis_history_events");
        $finance = $t->sis->financialEvents();
        $stage = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $stage->pdo->exec(
            "CREATE TABLE sis_academic_credits (id TEXT PRIMARY KEY,student_id TEXT,course_id TEXT," .
                "term_id TEXT,source_id TEXT,credit_type TEXT,course_code TEXT,course_title TEXT," .
                "attempted_milli INTEGER,completed_milli INTEGER,grade_points_milli INTEGER," .
                "grade_id TEXT,status TEXT,version INTEGER)",
        );
        $stage->pdo->exec(
            "CREATE TABLE sis_transfer_awards (id TEXT PRIMARY KEY,student_id TEXT," .
                "equivalency_id TEXT,completed_on TEXT,term_id TEXT,credit_id TEXT)",
        );
        $stage->pdo->exec(
            "CREATE TABLE sis_transfer_credit_streams (id TEXT PRIMARY KEY,student_id TEXT,credit_id TEXT)",
        );
        $repo = new SqlTransferCreditRepository(
            new \Infrastructure\Persistence\SqlRecords($stage->queryBuilder()),
        );
        $repo->create($root->source(), $root->credit());
        self::assertEquals($root->credit(), $repo->credit($t->credit));
        self::assertSame($t->award->value, $repo->award($t->award)["id"]);
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame($finance, $t->sis->financialEvents());
    }
    public function testCorrectionAuthorityIsRecheckedAfterWaitingForLocks(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $f->access->staff = false;
        $f->access->grants = ["sis:transfer:manage", "sis:student:read"];
        $events = $f->sql->rows("sis_history_events");
        $registration = $this->createStub(\Domain\Registration\Repository\RegistrationRepository::class);
        $registration->method("lockStudent")->willReturnCallback(function (EntityId $id) use ($f): void {
            $f->registration->lockStudent($id);
            $f->access->grants = [];
        });
        $service = new \Domain\Transfer\Service\TransferAwardService(
            $t->repository,
            $registration,
            $f->access,
            $f->transactions,
            $f->audit,
            $t->cipher,
            $f->clock,
            $f->transferCredits(),
        );
        try {
            $service->preview(
                $t->student,
                $t->award,
                new AwardChange("void", null, null, "Fictional revoked authority."),
            );
            self::fail("Revoked authority reviewed a correction.");
        } catch (AccessDeniedException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }
    public function testAnInitialAwardRollsBackItsJournalProjectionAndRoutingWhenAuditFails(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $f->sql,
            $f->gradeCatalog(),
        );
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(new RuntimeException("Fictional initial award failure."));
        $service = new \Domain\Transfer\Service\TransferService(
            new \Infrastructure\Persistence\Repository\SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit,
            $catalog,
            $f->transferCredits(),
        );
        $before = [];
        $cases2 = [
                "sis_academic_credits",
                "sis_transfer_awards",
                "sis_transfer_credit_streams",
                "sis_history_events",
                "sis_history_operations",
                "sis_history_streams",
            ];
        foreach ($cases2 as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $row = $catalog->get("equivalencies", $t->equivalency->id);
        try {
            $service->award(
                $t->student,
                $t->equivalency->id,
                $t->equivalency->externalInstitutionId,
                $f->term,
                new DateTimeImmutable("2026-05-02"),
                3,
                \Domain\Transfer\ValueObject\TransferRevision::of("equivalencies", $row),
            );
            self::fail("Partial initial award committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional initial award failure.", $e->getMessage());
        }
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
    }
    public function testAReceiptCannotClaimADifferentCredit(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        [$receipt, $review, $operation] = $this->post(
            $t,
            new AwardChange("amend", 4000, "B", "Fictional tampered receipt."),
        );
        $section = $f->section();
        $enrollment = $f->service->register($t->student, $section->id);
        $other = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        $f->sql->update(
            "sis_transfer_award_changes",
            ["credit_id" => $other["id"]],
            ["id" => $operation->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $t->service()->history($t->student, $t->award);
    }
}
