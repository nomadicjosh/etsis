<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\EnrollmentReinstatementFixture;
use PHPUnit\Framework\TestCase;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class EnrollmentReinstatementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        require_once dirname(__DIR__) . "/Support/EnrollmentReinstatementFixture.php";
    }
    public function testReviewedReinstatementPreservesAttemptCreditMoneyAndOriginalDropReceipt(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $credit = $s->sql->rows("sis_academic_credits")[0];
        $finance = array_map($s->sql->rows(...), [
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
        ]);
        $r = $f->review();
        self::assertTrue($r["can_confirm"]);
        self::assertSame("A", $r["restored_status"]);
        $result = $f->confirm($r);
        self::assertSame($f->enrollment->id->value, $result["enrollment_id"]);
        self::assertSame($credit["id"], $result["academic_credit_id"]);
        self::assertSame(2, $result["version"]);
        $restored = $s->sql->one("sis_academic_credits", ["id" => $credit["id"]]);
        self::assertSame("A", $restored["status"]);
        self::assertNull($restored["grade_id"]);
        self::assertSame(0, (int) $restored["completed_milli"]);
        self::assertSame(
            $finance,
            array_map($s->sql->rows(...), [
                "sis_student_accounts",
                "sis_account_entries",
                "sis_account_balances",
            ]),
        );
        $before = $s->sql->rows("sis_history_events");
        self::assertSame($result, $f->confirm($r));
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $d = $f->drop;
        $s->drops->commit(
            $f->student->id,
            $f->section->id,
            $f->enrollment->id,
            $d["version"],
            new EntityId($d["operation_id"]),
            $d["revision"],
            $d["effective_on"],
        );
        self::assertSame(
            "A",
            $s->registration->enrollment($f->student->id, $f->section->id)->status->value,
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }
    private function facts(EnrollmentReinstatementFixture $f): array
    {
        return array_map($f->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_registration_streams",
            "sis_registration_decisions",
            "sis_academic_credit_streams",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
            "sis_sections",
            "sis_audit",
        ]);
    }
    public function testExpiredReceiptPreservesLaterWithdrawalAndNewReinstatementKeepsSelfRegistrationOrigin(): void
    {
        $f = new EnrollmentReinstatementFixture(selfRegistered: true);
        $s = $f->sis;
        $r = $f->review();
        $result = $f->confirm($r);
        self::assertSame("N", $result["status"]);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        $d = $s->drops->review($f->student->id, $f->section->id);
        $s->drops->commit(
            $f->student->id,
            $f->section->id,
            $f->enrollment->id,
            $d["version"],
            new EntityId($d["operation_id"]),
            $d["revision"],
            $d["effective_on"],
        );
        $before = $this->facts($f);
        self::assertSame($result, $f->confirm($r));
        self::assertSame($before, $this->facts($f));
        self::assertSame(
            "W",
            $s->registration->enrollment($f->student->id, $f->section->id)->status->value,
        );
        $next = $f->confirm($f->review());
        self::assertSame("N", $next["status"]);
        self::assertSame(4, $next["version"]);
        self::assertSame(1, $s->registration->sectionCount($f->section->id));
    }
    public function testASeatTakenAfterReviewCannotProducePartialReinstatement(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $r = $f->review();
        $s->service->register($s->student($s->user())->id, $f->section->id, true);
        $before = $this->facts($f);
        try {
            $f->confirm($r);
            self::fail("A stale seat review reinstated a full class.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
        self::assertFalse($f->review()["can_confirm"]);
    }
    public function testFinalAuditAndAuthorityFailuresRollBackAllReinstatementFacts(): void
    {
        foreach (["failure", "duty", "offering"] as $case) {
            $f = new EnrollmentReinstatementFixture();
            $s = $f->sis;
            $r = $f->review();
            $before = $this->facts($f);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ) use (
                    $s,
                    $f,
                    $case
): void {
                    $s->audit->record($actor, $action, $subject);
                    if ($case === "failure") {
                        throw new \RuntimeException("Fictional required audit failure.");
                    }
                    if ($case === "duty") {
                        $s->access->staff = false;
                        $s->access->grants = ["sis:registration:manage", "sis:gradebook:closed"];
                    }
                    if ($case === "offering") {
                        $s->sql->update(
                            "sis_sections",
                            ["fee_minor" => 12000],
                            ["id" => $f->section->id->value],
                        );
                    }
                });
            try {
                $f->confirm($r, $audit);
                self::fail("A final failure left reinstatement facts.");
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }
    public function testExpiryIndependentDutiesAndForeignAttemptScopeAreEnforced(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $r = $f->review();
        $before = $this->facts($f);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $f->confirm($r);
            self::fail("An expired new decision was accepted.");
        } catch (ConcurrentModificationException | ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:registration:reinstate", "sis:registration:manage"];
        try {
            $f->review();
            self::fail("Closed-record duty was bypassed.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = true;
        $other = $s->student($s->user());
        $this->expectException(ConcurrentModificationException::class);
        $s->reinstatements()->review(
            $other->id,
            $f->section->id,
            $f->enrollment->id,
            "Fictional foreign scope.",
        );
    }
    public function testARealPostWithdrawalGradeCorrectionIsNotErasedAndDamagedProjectionFailsClosed(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $choices = $s
            ->academicCredits()
            ->choices($f->student->id, $f->section->id, $f->enrollment->id);
        $grades = array_column($choices["items"], "id", "code");
        $credit = $s->sql->rows("sis_academic_credits")[0];
        $review = $s
            ->academicCredits()
            ->review(
                $f->student->id,
                $f->section->id,
                $f->enrollment->id,
                new EntityId($grades["F"]),
                (int) $credit["version"],
                reason: "Fictional correction after withdrawal.",
            );
        $s->academicCredits()->commit($f->student->id, $f->section->id, $review["review_token"]);
        $before = $this->facts($f);
        try {
            $f->review();
            self::fail("A later academic decision was erased by restoration.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
        $s->sql->update(
            "sis_academic_credits",
            ["attempted_milli" => 9000],
            ["id" => $credit["id"]],
        );
        $this->expectException(CorruptHistoryException::class);
        $f->review();
    }

    public function testFrameworkReplaysBothRootsAndCuratedHistoryRetainsTheWithdrawal(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $result = $f->confirm($f->review());
        $store = \EventStoreFixture::framework($s->connection->queryBuilder());
        $enrollmentId = new \Domain\Registration\ValueObject\EnrollmentHistoryId(
            $f->enrollment->id->value,
        );
        $enrollment = \Domain\Registration\EnrollmentHistory::root($enrollmentId);
        foreach ($store->getAggregateHistoryFor($enrollmentId) as $event) {
            $enrollment->acceptHistorical($event);
        }
        $creditId = new \Domain\AcademicRecord\ValueObject\AcademicCreditId(
            $result["academic_credit_id"],
        );
        $credit = \Domain\AcademicRecord\AcademicCredit::root($creditId);
        foreach ($store->getAggregateHistoryFor($creditId) as $event) {
            $credit->acceptHistorical($event);
        }
        self::assertSame("A", $enrollment->facts()["status"]);
        self::assertSame(3, $enrollment->playhead());
        self::assertSame("A", $credit->credit()["status"]);
        self::assertNull($credit->credit()["grade_id"]);
        $summary = $s->enrollmentLedger()->summary($f->enrollment->id);
        self::assertSame(
            ["registered", "late_drop", "reinstated"],
            array_column($summary["items"], "action"),
        );
        $academic = $s
            ->academicCredits()
            ->history($f->student->id, $f->section->id, $f->enrollment->id);
        self::assertSame(
            ["registration", "dropped", "reinstated"],
            array_column($academic["items"], "action"),
        );
        self::assertFalse(
            str_contains(
                json_encode([$summary, $academic], JSON_THROW_ON_ERROR),
                "Fictional reviewed correction",
            ),
        );
    }
    public function testReinstatementCannotBypassAnOlderWaitlistRequest(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $filler = $s->student($s->user());
        $s->service->register($filler->id, $f->section->id, true);
        $w = $s->waitlists();
        $policy = $w->reviewPolicy(
            $f->section->id,
            new \Domain\Registration\Waitlist\WaitlistPolicy(true, 10)->describe(),
            0,
            "Fictional queue policy.",
        );
        $w->confirmPolicy(
            $f->section->id,
            new EntityId($policy["operation_id"]),
            $policy["policy"],
            $policy["version"],
            $policy["reason"],
            $policy["review_token"],
        );
        $queued = $s->student($s->user());
        $r = $w->review($queued->id, $f->section->id, "requested", true, null);
        $w->confirm(
            $queued->id,
            $f->section->id,
            new EntityId($r["operation_id"]),
            $r["action"],
            true,
            $r["revision"],
            $r["review_token"],
            $r["reason"],
        );
        $s->drops->drop($filler->id, $f->section->id);
        $before = $this->facts($f);
        $review = $f->review();
        self::assertFalse($review["can_confirm"]);
        self::assertFalse($review["student"]["waitlist"]["can_register"]);
        try {
            $f->confirm($review);
            self::fail("The oldest request lost its seat priority.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
    }
    public function testProofBindsReasonActorAndExactAttemptAndUnknownOriginalCourseCannotBeGuessed(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $r = $f->review();
        $before = $this->facts($f);
        $altered = $r;
        $altered["reason"] = "Fictional changed intent.";
        try {
            $f->confirm($altered);
            self::fail("The accepted review reason changed.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
        $s->access->actor = $s->user();
        try {
            $f->confirm($r);
            self::fail("Another registrar reused the proof.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($f));
        }
        $s->access->actor = $s->user();
        $section = $s->section();
        $student = $s->student($s->user());
        $enrolled = $s->service->register($student->id, $section->id, true);
        $s->drops->drop($student->id, $section->id);
        $this->expectException(ValidationException::class);
        $s->reinstatements()->review(
            $student->id,
            $section->id,
            $enrolled->id,
            "Fictional unknown original course.",
        );
    }
}
