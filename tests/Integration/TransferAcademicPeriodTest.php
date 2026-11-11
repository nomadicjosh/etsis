<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use PHPUnit\Framework\TestCase;

final class TransferAcademicPeriodTest extends TestCase
{
    private function fixture(bool $legacy = false): TransferAwardFixture
    {
        $h = new HiatusFixture();
        return new TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
    }
    private function record(TransferAwardFixture $t, ?string $date, int $version): array
    {
        $r = $t->periods()->review($t->student, $t->award, $date, $version, "Fictional sending-institution evidence.");
        return [$r, $t->periods()->commit($t->student, $t->award, $r["review_token"])];
    }
    private function correct(TransferAwardFixture $t, string $kind): void
    {
        $change = new AwardChange($kind, $kind === "void" ? null : 3000, $kind === "void" ? null : "B", "Fictional award correction.");
        $review = $t->service()->preview($t->student, $t->award, $change);
        $t->service()->confirm($t->student, $t->award, EntityId::generate(), $change, $review["revision"]);
    }
    public function testReviewedSourcePeriodPreservesAwardSourceAndFinancialFactsAndAddsNoParallelHistory(): void
    {
        $t = $this->fixture();
        $s = $t->sis;
        $credit = $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]);
        $awards = $s->sql->rows("sis_transfer_awards");
        $events = $s->sql->rows("sis_history_events");
        $finance = $s->financialEvents();
        $review = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional source verification.");
        self::assertNull($review["previous_academic_on"]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $t->periods()->commit($t->student, $t->award, $review["review_token"]);
        self::assertEquals(array_replace($credit, ["version" => 1]), $s->sql->one("sis_academic_credits", ["id" => $t->credit->value]));
        self::assertSame($awards, $s->sql->rows("sis_transfer_awards"));
        self::assertSame($finance, $s->financialEvents());
        $facts = $s->transferCredits()->auditFacts($t->credit, $t->student, new HistoryReadBudget());
        self::assertSame("2026-01-12", $facts["academic_on"]);
        self::assertSame("2026-05-01", $facts["completed_on"]);
        self::assertSame("2026-01-12", $t->service()->view($t->student, $t->award)["academic_on"]);
        foreach ($events as $event) {
            self::assertSame($event, $s->sql->one("sis_history_events", ["id" => $event["id"]]));
        }
        $history = $t->periods()->history($t->student, $t->award);
        self::assertSame(["award", "period_recorded"], array_column($history["items"], "action"));
        self::assertStringNotContainsString("Fictional source verification", json_encode($history, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey("actor_id", $history["items"][1]);
    }
    public function testGradeChangesVoidAndReinstatementRetainSourcePeriodAndOldRetryCannotUndoClear(): void
    {
        $t = $this->fixture();
        [$first, $receipt] = $this->record($t, "2026-01-12", 0);
        $this->correct($t, "amend");
        self::assertSame("2026-01-12", $t->service()->view($t->student, $t->award)["academic_on"]);
        $this->correct($t, "void");
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $first["review_token"]));
        $this->correct($t, "reinstate");
        $this->record($t, "2026-02-01", 4);
        $this->record($t, null, 5);
        $events = $t->sis->sql->rows("sis_history_events");
        $t->sis->clock->time = $t->sis->clock->time->modify("+1 day");
        self::assertSame($receipt, $t->periods()->commit($t->student, $t->award, $first["review_token"]));
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertNull($t->service()->view($t->student, $t->award)["academic_on"]);
    }
    public function testUnchangedMalformedFutureOrPostCompletionDatesCannotBecomePeriodEvidence(): void
    {
        $t = $this->fixture();
        $events = $t->sis->sql->rows("sis_history_events");
        foreach ([null, "2026-02-30", "2026-05-02", "2026-08-11", "2026-1-1", "2026-01-01\0"] as $date) {
            try {
                $t->periods()->review($t->student, $t->award, $date, 0, "Fictional invalid source.");
                self::fail("Unsupported source chronology accepted.");
            } catch (ValidationException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            }
        }
    }
    public function testUntrackedAwardCannotReceiveAnInventedOpeningHistoryOrPeriod(): void
    {
        $t = $this->fixture(true);
        $events = $t->sis->sql->rows("sis_history_events");
        try {
            $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional untracked source.");
            self::fail("Untracked award accepted a period without a retained opening.");
        } catch (ValidationException) {
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        }
    }
    public function testExpiredReviewAndCompetingAwardChangeLeaveNoPartialPeriodOrReceipt(): void
    {
        foreach (["expiry", "award"] as $mode) {
            $t = $this->fixture();
            $r = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional pending source.");
            if ($mode === "expiry") $t->sis->clock->time = $t->sis->clock->time->modify("+21 minutes");
            else $this->correct($t, "amend");
            $events = $t->sis->sql->rows("sis_history_events");
            $receipts = $t->sis->sql->rows("sis_history_operations");
            try {
                $t->periods()->commit($t->student, $t->award, $r["review_token"]);
                self::fail("Stale source review accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
                self::assertSame($receipts, $t->sis->sql->rows("sis_history_operations"));
                self::assertNull($t->service()->view($t->student, $t->award)["academic_on"]);
            }
        }
    }
    public function testAwardScopeAndBothRegistrarPermissionsAreRequiredAtConfirmation(): void
    {
        $t = $this->fixture();
        $r = $t->periods()->review($t->student, $t->award, "2026-01-12", 0, "Fictional scoped source.");
        $t->sis->access->staff = false;
        foreach ([["sis:transfer:manage"], ["sis:student:read"]] as $grants) {
            $t->sis->access->grants = $grants;
            try {
                $t->periods()->commit($t->student, $t->award, $r["review_token"]);
                self::fail("Incomplete registrar authority accepted.");
            } catch (AccessDeniedException) { self::assertTrue(true); }
        }
        $t->sis->access->grants = ["sis:transfer:manage", "sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $t->periods()->review($t->sis->student()->id, $t->award, "2026-01-12", 0, "Fictional foreign award.");
    }
    public function testLimitedHistoryAndCorruptPriorPeriodCannotBeUsedAsNewAcademicFacts(): void
    {
        $t = $this->fixture();
        $this->record($t, "2026-01-12", 0);
        $t->sis->transactions->run(function () use ($t): void {
            $t->sis->registration->lockStudent($t->student);
            try {
                $t->sis->transferCredits()->decisionRecord($t->student, $t->award, new HistoryReadBudget(maximumEvents: 1));
                self::fail("A partial transfer stream escaped its replay budget.");
            } catch (HistoryReadLimitException) { self::assertTrue(true); }
            [$root] = $t->sis->transferCredits()->decisionRecord($t->student, $t->award, new HistoryReadBudget());
            $event = \Domain\Transfer\Event\TransferAcademicPeriodWasRecorded::occur(
                new \Domain\Transfer\ValueObject\TransferCreditId($t->award->value),
                ["decision_id" => EntityId::generate()->value, "previous_decision_id" => $root->decision(), "previous_academic_on" => null, "academic_on" => "2026-02-01"],
                ["__aggregate_type" => "transfer_credit"],
            )->withPlayhead($root->playhead() + 1);
            $this->expectException(CorruptHistoryException::class);
            $root->acceptHistorical($event);
        });
    }
    public function testPeriodHistoryPagesFreezeTheirHeadAndOmitProtectedEvidence(): void
    {
        $t = $this->fixture();
        for ($i = 1; $i <= 22; $i++) {
            $this->record($t, sprintf("2026-01-%02d", $i), $i - 1);
        }
        $first = $t->periods()->history($t->student, $t->award);
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_cursor"]);
        $this->record($t, "2026-02-01", 22);
        $second = $t->periods()->history($t->student, $t->award, $first["next_cursor"]);
        self::assertCount(3, $second["items"]);
        self::assertNull($second["next_cursor"]);
        self::assertSame("2026-01-22", $second["items"][2]["academic_on"]);
        self::assertStringNotContainsString("Fictional sending-institution evidence", json_encode($second, JSON_THROW_ON_ERROR));
    }
    public function testAuditFailureAndRevokedAuthorityRollBackSourceDateEventReceiptAndProjection(): void
    {
        foreach (["audit", "revoke"] as $failure) {
            $t = $this->fixture();
            $audit = new class ($t, $failure) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(private TransferAwardFixture $t, private string $failure) {}
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->failure === "audit") throw new RuntimeException("Fictional audit failure.");
                    $this->t->sis->access->staff = false;
                    $this->t->sis->access->grants = [];
                }
                public function forSubject(EntityId $subject): array { return []; }
            };
            $service = $t->periods($audit);
            $review = $service->review($t->student, $t->award, "2026-01-12", 0, "Fictional atomic source.");
            $events = $t->sis->sql->rows("sis_history_events");
            $receipts = $t->sis->sql->rows("sis_history_operations");
            try {
                $service->commit($t->student, $t->award, $review["review_token"]);
                self::fail("Failed source decision committed.");
            } catch (RuntimeException | AccessDeniedException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
                self::assertSame($receipts, $t->sis->sql->rows("sis_history_operations"));
                self::assertNull($t->sis->transferCredits()->auditFacts($t->credit, $t->student)["academic_on"]);
            }
        }
    }
}
