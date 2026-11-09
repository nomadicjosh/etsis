<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
require_once dirname(__DIR__) . "/Support/HiatusFixture.php";

use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CourseDropWorkflowTest extends TestCase
{
    private function commit(SisFixture $f, array $review, ?EntityId $operation = null): array
    {
        return $f->drops->commit(
            new EntityId($review["student_id"]),
            new EntityId($review["section_id"]),
            new EntityId($review["enrollment_id"]),
            $review["version"],
            $operation ?? new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
    }

    private function account(SisFixture $f, EntityId $student): AccountId
    {
        return new AccountId(
            $f->sql->one("sis_student_accounts", [
                "student_id" => $student->value,
                "term_id" => $f->term->value,
            ])["id"],
        );
    }

    private function grades(SisFixture $f): void
    {
        $repository = new \Infrastructure\Persistence\Repository\SqlGradebookRepository(
            $f->sql,
            $f->gradeCatalog(),
        );
        $f->legacyGrade(new GradeDefinition(EntityId::generate(), "D", 1000, true, true));
        $f->legacyGrade(new GradeDefinition(EntityId::generate(), "W", 0, false, false));
    }

    public function testInclusiveGraceDeadlineRemovesAcademicRecordsAndReversesOriginalChargeWithoutCashRefund(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $account = $this->account($f, $student->id);
        // Current pricing is unrelated to the amount actually charged at registration.
        $f->sql->update("sis_sections", ["fee_minor" => 25000], ["id" => $section->id->value]);
        $f->clock->time = new DateTimeImmutable("2026-08-15T23:59:59-07:00");
        $review = $f->drops->review($student->id, $section->id);
        self::assertTrue($review["outcome"]["delete_records"]);
        $receipt = $this->commit($f, $review);
        self::assertTrue($receipt["academic_records_removed"]);
        self::assertSame("reversed", $receipt["course_charge"]);
        self::assertFalse($receipt["cash_refund_issued"]);
        self::assertNull($f->registration->enrollment($student->id, $section->id));
        self::assertSame([], $f->sql->rows("sis_academic_credits"));
        $balance = $f->accounts->get($account);
        self::assertSame(0, $balance->balance()->minor);
        self::assertSame(
            [
                "operation_id" => $enrollment->id->value,
                "type" => "charge",
                "minor" => 10000,
                "currency" => "USD",
                "reversed" => true,
            ],
            $balance->entry($enrollment->id),
        );
        self::assertCount(1, $f->sql->rows("sis_course_drop_receipts"));
        self::assertStringNotContainsString(
            "Alex",
            json_encode($f->sql->rows("sis_course_drop_receipts"), JSON_THROW_ON_ERROR),
        );
        self::assertSame($receipt, $f->drops->receipt(new EntityId($review["operation_id"])));
    }

    public function testPaymentAndOtherChargesDetermineWhetherReversalLeavesCreditOrAnAmountOwed(): void
    {
        $cases1 = [[0, 0, 0], [10000, 0, -10000], [5000, 0, -5000], [5000, 7000, 2000], [10000, 7000, -3000]];
        foreach ($cases1 as [$payment, $otherCharge, $expected]) {
            $f = new SisFixture();
            $student = $f->student();
            $section = $f->section();
            $f->service->register($student->id, $section->id);
            $id = $this->account($f, $student->id);
            $account = $f->accounts->get($id);
            if ($payment) {
                $account->post(
                    EntityId::generate(),
                    EntryType::Payment,
                    new Money($payment, "USD"),
                    $f->access->actor,
                );
            }
            if ($otherCharge) {
                $account->post(
                    EntityId::generate(),
                    EntryType::Charge,
                    new Money($otherCharge, "USD"),
                    $f->access->actor,
                );
            }
            $f->accounts->save($account);
            $this->commit($f, $f->drops->review($student->id, $section->id));
            $after = $f->accounts->get($id);
            self::assertSame($expected, $after->balance()->minor);
            self::assertCount(1 + (int) ($payment > 0) + (int) ($otherCharge > 0), $after->entries());
            self::assertSame(
                [],
                array_filter(
                    $after->entries(),
                    fn(array $entry) => $entry["type"] === EntryType::Refund->value,
                ),
            );
        }
    }

    public function testFreeCourseNeedsNoFinancialAccountAndEarlyReaddGetsANewSourceWhileOldRetryDoesNothing(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(20, 0);
        $first = $f->service->register($student->id, $section->id);
        $review = $f->drops->review($student->id, $section->id);
        $receipt = $this->commit($f, $review);
        self::assertSame("none", $receipt["course_charge"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        $again = $f->service->register($student->id, $section->id);
        self::assertNotSame($first->id->value, $again->id->value);
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($receipt, $this->commit($f, $review));
        self::assertSame(
            $again->id->value,
            $f->registration->enrollment($student->id, $section->id)->id->value,
        );
        try {
            $this->commit($f, $review, EntityId::generate());
            self::fail("Stale review dropped a new enrollment.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows("sis_academic_credits"));
        }
    }

    public function testPaidSectionCanBeReaddedAndOnlyTheNewChargeIsPayable(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $first = $f->service->register($student->id, $section->id);
        $review = $f->drops->review($student->id, $section->id);
        $receipt = $this->commit($f, $review);
        $again = $f->service->register($student->id, $section->id);
        $account = $this->account($f, $student->id);
        self::assertSame(10000, $f->accounts->get($account)->balance()->minor);
        self::assertTrue($f->accounts->get($account)->entry($first->id)["reversed"]);
        self::assertFalse($f->accounts->get($account)->entry($again->id)["reversed"]);
        try {
            $this->commit($f, $review, EntityId::generate());
            self::fail("A same-day stale review dropped the replacement enrollment.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $again->id->value,
                $f->registration->enrollment($student->id, $section->id)->id->value,
            );
        }
        $events = $f->sql->rows("sis_history_events");
        self::assertSame($receipt, $this->commit($f, $review));
        self::assertSame($again->id->value, $f->service->register($student->id, $section->id)->id->value);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }

    public function testLateDAndWPeriodsKeepAcademicRecordsAndChargesAndUseExplicitGpaSettings(): void
    {
        $cases2 = [["2026-09-01T23:59:59-07:00", "D", 3000], ["2026-09-02T00:00:00-07:00", "W", 0]];
        foreach ($cases2 as [$time, $code, $points]) {
            $f = new SisFixture();
            $this->grades($f);
            $student = $f->student();
            $section = $f->section();
            $enrollment = $f->service->register($student->id, $section->id);
            $f->dropPolicies->save(
                $f->term,
                -1,
                new DropWindows([
                    ["through" => "2026-09-01", "grade_code" => "D"],
                    ["through" => null, "grade_code" => "W"],
                ]),
            );
            $f->clock->time = new DateTimeImmutable($time);
            $review = $f->drops->review($student->id, $section->id);
            $events = $f->financialEvents();
            $receipt = $this->commit($f, $review);
            self::assertSame($code, $receipt["grade_code"]);
            self::assertFalse($receipt["academic_records_removed"]);
            self::assertSame("W", $f->registration->enrollment($student->id, $section->id)->status->value);
            $credit = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
            self::assertSame(3000, $credit["attempted_milli"]);
            self::assertSame(0, $credit["completed_milli"]);
            self::assertSame($points, $credit["grade_points_milli"]);
            self::assertSame($code, $f->sql->one("sis_grades", ["id" => $credit["grade_id"]])["code"]);
            self::assertSame("kept", $receipt["course_charge"]);
            self::assertSame($events, $f->financialEvents());
            self::assertSame(10000, $f->accounts->get($this->account($f, $student->id))->balance()->minor);
        }
    }

    public function testDefaultLateMarkerRemainsWWithZeroEarnedCreditAndNoGpa(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $f->clock->time = new DateTimeImmutable("2026-08-16T00:00:00-07:00");
        $review = $f->drops->review($student->id, $section->id);
        self::assertFalse($review["outcome"]["counts_gpa"]);
        self::assertSame("W", $this->commit($f, $review)["grade_code"]);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertNull($credit["grade_id"]);
        self::assertSame(0, $credit["grade_points_milli"]);
    }

    public function testAuditFailureRollsBackDeletionReversalAndReceipt(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $events = $f->sql->rows("sis_history_events");
        $failure = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $f->dropService(audit: $failure);
        $r = $service->review($student->id, $section->id);
        try {
            $service->commit(
                $student->id,
                $section->id,
                new EntityId($r["enrollment_id"]),
                $r["version"],
                new EntityId($r["operation_id"]),
                $r["revision"],
                $r["effective_on"],
            );
            self::fail("Audit failure committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure.", $e->getMessage());
        }
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
        self::assertCount(1, $f->sql->rows("sis_academic_credits"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_course_drop_receipts"));
        $this->commit($f, $r);
        self::assertSame(0, $f->accounts->get($this->account($f, $student->id))->balance()->minor);
    }

    public function testStaleAcademicCreditOrDateRejectsWithoutFinancialWrites(): void
    {
        foreach (["credit", "date", "policy"] as $change) {
            $f = new SisFixture();
            $student = $f->student();
            $section = $f->section();
            $this->grades($f);
            $f->service->register($student->id, $section->id);
            if ($change === "policy") {
                $f->clock->time = new DateTimeImmutable("2026-08-16T12:00:00-07:00");
            }
            $r = $f->drops->review($student->id, $section->id);
            if ($change === "credit") {
                $f->sql->update("sis_academic_credits", ["version" => 1], ["id" => $r["credit"]["id"]]);
            } elseif ($change === "date") {
                $f->clock->time = $f->clock->time->modify("+1 day");
            } else {
                $f->dropPolicies->save(
                    $f->term,
                    -1,
                    new DropWindows([["through" => null, "grade_code" => "D"]]),
                );
            }
            $events = $f->sql->rows("sis_history_events");
            try {
                $this->commit($f, $r);
                self::fail("Stale drop was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertCount(1, $f->sql->rows("sis_enrollments"));
            }
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_course_drop_receipts"));
        }
    }

    public function testReceiptIsActorBoundAndCurrentAccessIsRequiredEvenForReplay(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $r = $f->drops->review($student->id, $section->id);
        $this->commit($f, $r);
        $op = new EntityId($r["operation_id"]);
        $f->access->actor = $f->user();
        foreach ([fn() => $this->commit($f, $r), fn() => $f->drops->receipt($op)] as $call) {
            try {
                $call();
                self::fail("Another actor read a receipt.");
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows("sis_course_drop_receipts"));
            }
        }
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $f->drops->receipt($op);
    }

    public function testCustomPoliciesRequireCatalogPermissionAndCompareVersions(): void
    {
        $f = new SisFixture();
        $this->grades($f);
        $windows = new DropWindows([["through" => null, "grade_code" => "W"]]);
        self::assertSame(-1, $f->dropPolicies->show($f->term)["version"]);
        $f->dropPolicies->save($f->term, -1, $windows);
        self::assertSame(0, $f->dropPolicies->show($f->term)["version"]);
        try {
            $f->dropPolicies->save($f->term, -1, $windows);
            self::fail("Stale policy saved.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows("sis_term_drop_policies"));
        }
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $cases3 = [fn() => $f->dropPolicies->show($f->term), fn() => $f->dropPolicies->save($f->term, 0, $windows)];
        foreach ($cases3 as $call) {
            try {
                $call();
                self::fail("Registrar changed catalog policy without authority.");
            } catch (AccessDeniedException) {
                self::assertSame(0, $f->sql->rows("sis_term_drop_policies")[0]["version"]);
            }
        }
    }

    public function testPoliciesRejectMissingGradesAndCutoffsOnOrBeforeGrace(): void
    {
        $f = new SisFixture();
        $this->grades($f);
        $cases4 = [
                [["through" => null, "grade_code" => "MISSING"]],
                [["through" => "2026-08-15", "grade_code" => "W"], ["through" => null, "grade_code" => "D"]],
            ];
        foreach ($cases4 as $windows) {
            try {
                $f->dropPolicies->save($f->term, -1, new DropWindows($windows));
                self::fail("Invalid policy saved.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_term_drop_policies"));
            }
        }
        $cases5 = [
                [],
                [["through" => "2026-09-01", "grade_code" => "D"]],
                [["through" => null, "grade_code" => "W"], ["through" => null, "grade_code" => "D"]],
                [["through" => "2026-02-30", "grade_code" => "D"], ["through" => null, "grade_code" => "W"]],
            ];
        foreach ($cases5 as $windows) {
            try {
                new DropWindows($windows);
                self::fail("Invalid windows accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_term_drop_policies"));
            }
        }
    }

    public function testStudentCannotUseBackdatedDropToReverseLateCharge(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $f->access->staff = false;
        $f->clock->time = new DateTimeImmutable("2026-08-20T12:00:00-07:00");
        $this->expectException(AccessDeniedException::class);
        $f->drops->review($student->id, $section->id, "2026-08-10");
    }

    public function testGuidedEarlyWithdrawalReversesOnlySelectedCourseChargesAtomically(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $a = $f->section();
        $b = $f->section();
        $f->service->register($student->id, $a->id);
        $f->service->register($student->id, $b->id);
        $dates = new \Domain\Shared\ValueObject\DateRange(new DateTimeImmutable("2026-08-10"));
        $workflow = $h->workflow();
        $r = $workflow->review($student->id, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
        $impact = new \Domain\Student\ValueObject\HiatusImpact(false, $r["revision"], [], $a->id);
        $hiatus = new \Domain\Student\StudentHiatus(
            new EntityId($r["operation_id"]),
            $student->id,
            \Domain\Student\Enum\HiatusCode::Withdrawal,
            $dates,
        );
        $receipt = $workflow->commit($hiatus, "Fictional grace-period withdrawal", $impact);
        self::assertNull($f->registration->enrollment($student->id, $a->id));
        self::assertNotNull($f->registration->enrollment($student->id, $b->id));
        self::assertSame(10000, $f->accounts->get($this->account($f, $student->id))->balance()->minor);
        self::assertTrue($receipt["impact"]["course_changes"][0]["academic_records_removed"]);
        $events = $f->sql->rows("sis_history_events");
        self::assertSame($receipt, $workflow->commit($hiatus, "Fictional grace-period withdrawal", $impact));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }
    public function testRootHiatusFailureRollsBackNestedEarlyChargeReversalAndAcademicRemoval(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $events = $f->sql->rows("sis_history_events");
        $failure = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional parent failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $workflow = $h->workflow(audit: $failure);
        $dates = new \Domain\Shared\ValueObject\DateRange(new DateTimeImmutable("2026-08-10"));
        $r = $workflow->review($student->id, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
        try {
            $workflow->commit(
                new \Domain\Student\StudentHiatus(
                    new EntityId($r["operation_id"]),
                    $student->id,
                    \Domain\Student\Enum\HiatusCode::Withdrawal,
                    $dates,
                ),
                "Fictional rollback",
                new \Domain\Student\ValueObject\HiatusImpact(true, $r["revision"], [], $section->id),
            );
            self::fail("Parent failure committed nested drop.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional parent failure.", $e->getMessage());
        }
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
        self::assertCount(1, $f->sql->rows("sis_academic_credits"));
        self::assertSame([], $f->sql->rows("sis_course_drop_receipts"));
        self::assertSame([], $f->sql->rows("sis_student_hiatuses"));
        self::assertTrue($f->admissions->student($student->id)->active);
    }
}
