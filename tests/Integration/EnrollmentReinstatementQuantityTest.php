<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EnrollmentReinstatementFixture;

final class EnrollmentReinstatementQuantityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        require_once dirname(__DIR__) . "/Support/EnrollmentReinstatementFixture.php";
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
            "sis_sections",
            "sis_audit",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
            "sis_load_determinations",
        ]);
    }
    private function events(EnrollmentReinstatementFixture $f): array
    {
        $credit = $f->sis->sql->rows("sis_academic_credits")[0];
        return iterator_to_array(
            \EventStoreFixture::framework(
                $f->sis->connection->queryBuilder(),
            )->getAggregateHistoryFor(new AcademicCreditId($credit["id"])),
        );
    }
    private function root(array $events): AcademicCredit
    {
        return AcademicCredit::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream(
                $events[0]->aggregateId(),
                array_values($events),
            ),
        );
    }
    public static function quantities(): array
    {
        return [[[4500], 4500], [[0], 0], [[4500, 3000], 3000]];
    }
    #[DataProvider("quantities")]
    public function testReviewedRestorationPreservesCorrectedLoadOriginalAwardAndEarlierFacts(
        array $quantities,
        int $expected,
    ): void {
        $f = new EnrollmentReinstatementFixture(registeredQuantities: $quantities);
        $s = $f->sis;
        $credit = $s->sql->rows("sis_academic_credits")[0];
        $history = $s->sql->rows("sis_history_events");
        $money = array_map($s->sql->rows(...), [
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
        ]);
        $r = $f->review();
        $basis = [
            "original_milli" => 3000,
            "registered_milli" => $expected,
            "correction_count" => count($quantities),
        ];
        self::assertTrue($r["can_confirm"]);
        self::assertSame($basis, $r["registered_credit_restoration"]);
        self::assertSame(3000, $r["attempted_milli"]);
        $result = $f->confirm($r);
        self::assertSame($credit["id"], $result["academic_credit_id"]);
        $events = $this->events($f);
        self::assertEquals($basis, end($events)->payload()["registered_credit_restoration"]);
        $root = $this->root($events);
        self::assertSame($expected, $root->registeredCredits()["attempted_milli"]);
        self::assertSame(3000, $root->originalRegisteredCredits()["attempted_milli"]);
        self::assertSame(3000, $root->credit()["attempted_milli"]);
        self::assertSame(0, $root->credit()["completed_milli"]);
        self::assertNull($root->credit()["grade_id"]);
        $pursuit = new EntityId($s->sql->rows("sis_student_programs")[0]["id"]);
        self::assertSame(
            $expected,
            $s->enrollmentLoad()->review($f->student->id, $pursuit, $s->term, "staff")[
                "credits_milli"
            ],
        );
        self::assertSame(
            $money,
            array_map($s->sql->rows(...), [
                "sis_student_accounts",
                "sis_account_entries",
                "sis_account_balances",
            ]),
        );
        foreach ($history as $event) {
            self::assertSame($event, $s->sql->one("sis_history_events", ["id" => $event["id"]]));
        }
        $page = $s
            ->academicCredits()
            ->history($f->student->id, $f->section->id, $f->enrollment->id);
        $reinstated = array_values(
            array_filter(
                $page["items"],
                static fn(array $item): bool => $item["action"] === "reinstated",
            ),
        );
        self::assertSame($basis, $reinstated[0]["registered_credit_restoration"]);
        self::assertArrayNotHasKey("reason", $page["items"][0]);
        self::assertArrayNotHasKey("actor_id", $page["items"][0]);
        $before = $this->facts($f);
        $s->clock->time = $s->clock->time->modify("+1 day");
        self::assertSame($result, $f->confirm($r));
        foreach ($f->registeredCorrections as $correction) {
            self::assertSame(
                $correction["receipt"],
                $f->registeredCredits()->confirm(...$correction["args"]),
            );
        }
        self::assertSame($before, $this->facts($f));
    }
    public function testUncorrectedEarlierShapeRemainsUnchanged(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $r = $f->review();
        self::assertArrayNotHasKey("registered_credit_restoration", $r);
        $f->confirm($r);
        $events = $this->events($f);
        self::assertArrayNotHasKey("registered_credit_restoration", end($events)->payload());
        self::assertSame(3000, $this->root($events)->registeredCredits()["attempted_milli"]);
    }
    public function testLaterQuantityAndWithdrawalNeedANewReviewAndKeepTheOriginalReceipt(): void
    {
        $f = new EnrollmentReinstatementFixture(registeredQuantities: [4500]);
        $s = $f->sis;
        $r = $f->review();
        $first = $f->confirm($r);
        $f->correctRegisteredCredits(2000);
        $drop = $s->drops->review($f->student->id, $f->section->id);
        $s->drops->commit(
            $f->student->id,
            $f->section->id,
            $f->enrollment->id,
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        $before = $this->facts($f);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($first, $f->confirm($r));
        self::assertSame($before, $this->facts($f));
        self::assertSame(
            "W",
            $s->registration->enrollment($f->student->id, $f->section->id)->status->value,
        );
        $next = $f->review();
        self::assertSame(
            ["original_milli" => 3000, "registered_milli" => 2000, "correction_count" => 2],
            $next["registered_credit_restoration"],
        );
        $f->confirm($next);
        $root = $this->root($this->events($f));
        self::assertSame(2000, $root->registeredCredits()["attempted_milli"]);
        self::assertSame(3000, $root->credit()["attempted_milli"]);
    }
    public function testFinalFailuresRollBackCorrectedReinstatementWithoutLosingItsEarlierCorrection(): void
    {
        foreach (["audit", "authority", "quantity", "registered"] as $failure) {
            $f = new EnrollmentReinstatementFixture(registeredQuantities: [4500]);
            $s = $f->sis;
            $r = $f->review();
            $before = $this->facts($f);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($s, $f, $failure): void {
                    if ($failure === "audit") {
                        throw new \RuntimeException("Fictional required audit failure.");
                    }
                    if ($failure === "authority") {
                        $s->access->staff = false;
                        return;
                    }
                    if ($failure === "registered") {
                        $f->correctRegisteredCredits(2500);
                        return;
                    }
                    $s->sql->execute("UPDATE sis_academic_credits SET attempted_milli = 4500");
                });
            try {
                $f->confirm($r, $audit);
                self::fail("A failed final validation retained a restoration.");
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }
    public function testLaterWithdrawnGradeCorrectionStillRequiresSeparateAcademicResolution(): void
    {
        $f = new EnrollmentReinstatementFixture(registeredQuantities: [4500]);
        $s = $f->sis;
        $pending = $f->review();
        $ledger = $s->academicCredits();
        $choices = $ledger->choices($f->student->id, $f->section->id, $f->enrollment->id);
        $grades = array_column($choices["items"], "id", "code");
        $credit = $s->sql->rows("sis_academic_credits")[0];
        $r = $ledger->review(
            $f->student->id,
            $f->section->id,
            $f->enrollment->id,
            new EntityId($grades["F"]),
            $credit["version"],
            reason: "Fictional later withdrawn-grade correction.",
        );
        $ledger->commit($f->student->id, $f->section->id, $r["review_token"]);
        $before = $this->facts($f);
        foreach (["review", "confirm"] as $action) {
            try {
                $action === "review" ? $f->review() : $f->confirm($pending);
                self::fail("A later academic correction was overwritten by reinstatement.");
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    private function determinations(
        \SisFixture $s,
    ): \Domain\Student\Service\LoadDeterminationService {
        return new \Domain\Student\Service\LoadDeterminationService(
            $s->enrollmentLoad(),
            new \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository($s->sql),
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $this->createStub(\Domain\Advising\Service\AdvisorProgressAccess::class),
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
        );
    }
    public function testNewLoadReviewsUseTheRestoredQuantityAndEarlierDeterminationsRemainIntact(): void
    {
        $f = new EnrollmentReinstatementFixture(registeredQuantities: [4500]);
        $s = $f->sis;
        $student = $f->student->id;
        $pursuit = new EntityId($s->sql->rows("sis_student_programs")[0]["id"]);
        $f->confirm($f->review());
        $policy = $s->loadPolicies();
        $p = $policy->review(
            null,
            [
                "bands" => [
                    ["code" => "PART", "label" => "Part time", "min_credits_milli" => 0],
                    ["code" => "FULL", "label" => "Full time", "min_credits_milli" => 4000],
                ],
            ],
            $policy->view()["revision"],
            "Fictional institution load policy.",
        );
        $policy->publish(
            null,
            new EntityId($p["operation_id"]),
            $p["definition"],
            $p["revision"],
            $p["reason"],
            $p["review_token"],
        );
        $service = $this->determinations($s);
        $record = static fn(array $r): array => $service->confirm(
            $student,
            $pursuit,
            $s->term,
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["reason"],
            $r["review_token"],
        );
        $old = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional restored-load observation.",
        );
        $receipt = $record($old);
        $original = $s->sql->rows("sis_load_determinations");
        self::assertSame(
            4500,
            $service->listing($student, $pursuit, $s->term, "staff")["items"][0]["credits_milli"],
        );
        $f->correctRegisteredCredits(2000);
        self::assertSame($original, $s->sql->rows("sis_load_determinations"));
        self::assertSame(
            2000,
            $s->enrollmentLoad()->review($student, $pursuit, $s->term, "staff")["credits_milli"],
        );
        $fresh = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional later load observation.",
        );
        $record($fresh);
        $before = $this->facts($f);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($receipt, $record($old));
        self::assertSame($before, $this->facts($f));
        self::assertSame(
            $original[0],
            $s->sql->one("sis_load_determinations", ["id" => $original[0]["id"]]),
        );
    }

    public static function damagedBasis(): array
    {
        return [["missing"], ["quantity"], ["original"], ["count"], ["unknown"], ["invented"]];
    }
    #[DataProvider("damagedBasis")]
    public function testReplayRejectsMissingOrTransplantedQuantityEvidence(string $damage): void
    {
        $f = new EnrollmentReinstatementFixture(
            registeredQuantities: $damage === "invented" ? [] : [4500],
        );
        $f->confirm($f->review());
        $events = $this->events($f);
        $last = array_pop($events);
        $payload = $last->payload();
        if ($damage === "invented") {
            $payload["registered_credit_restoration"] = [
                "original_milli" => 3000,
                "registered_milli" => 4500,
                "correction_count" => 1,
            ];
        } elseif ($damage === "missing") {
            unset($payload["registered_credit_restoration"]);
        } elseif ($damage === "unknown") {
            $payload["registered_credit_restoration"]["private_note"] = "fictional";
        } else {
            $key = [
                "quantity" => "registered_milli",
                "original" => "original_milli",
                "count" => "correction_count",
            ][$damage];
            ++$payload["registered_credit_restoration"][$key];
        }
        $root = $this->root($events);
        $event = \Domain\AcademicRecord\Event\InstitutionalCreditWasReinstated::occur(
            new AcademicCreditId((string) $last->aggregateId()),
            $payload,
            ["__aggregate_type" => "academic_credit"],
        )->withPlayhead($last->playhead());
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($event);
    }
}
