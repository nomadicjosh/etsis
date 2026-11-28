<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\Service\TuitionLedger;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentGatewayFixture;

final class TuitionInvoicingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/PaymentGatewayFixture.php";
    }
    private function configured(): array
    {
        $g = new PaymentGatewayFixture();
        $f = $g->sis;
        $student = $f->student();
        $service = $f->tuition();
        $brackets = [
            [
                "from_milli" => 0,
                "through_milli" => 11999,
                "flat_minor" => 0,
                "rate_minor" => 10000,
                "rate_from_milli" => 0,
            ],
            [
                "from_milli" => 12000,
                "through_milli" => 18000,
                "flat_minor" => 100000,
                "rate_minor" => 0,
                "rate_from_milli" => 12000,
            ],
            [
                "from_milli" => 18001,
                "through_milli" => 999999,
                "flat_minor" => 100000,
                "rate_minor" => 10000,
                "rate_from_milli" => 18000,
            ],
        ];
        $r = $service->reviewSchedule(
            null,
            0,
            "Fictional tuition",
            "USD",
            $g->income,
            $brackets,
            "Approve fictional tuition brackets.",
        );
        $schedule = $service->confirm($r["review_token"]);
        $r = $service->reviewAssignment(
            "term",
            $f->term,
            new EntityId($schedule["id"]),
            0,
            "Assign fictional term tuition.",
        );
        $service->confirm($r["review_token"]);
        return [$g, $student, new EntityId($schedule["id"])];
    }
    public function testRegistrationCreatesPendingChargesAndReviewedInvoicePostsTheirOriginalIncome(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 1000)->id);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        self::assertSame([], $f->sql->rows("sis_journals"));
        $id = TuitionLedger::billId($student->id, $f->term);
        $bill = $f->tuition()->bill($id);
        self::assertSame(31000, $bill["pending_minor"]);
        self::assertSame(3000, $bill["credits_milli"]);
        $r = $f->tuition()->reviewInvoice(
            $id,
            $bill["version"],
            array_map(
                static fn(array $l): array => [
                    "key" => $l["key"],
                    "minor" => $l["minor"],
                ],
                $bill["lines"],
            ),
            "2026-08-31",
            "Issue fictional reviewed tuition.",
        );
        $result = $f->tuition()->confirm($r["review_token"]);
        $invoice = $f->tuition()->invoice(new EntityId($result["id"]), "owner");
        self::assertSame(31000, $invoice["total_minor"]);
        $account = $f->accounts->get(new AccountId($invoice["account_id"]));
        self::assertSame(31000, $account->balance()->minor);
        self::assertCount(2, $f->sql->rows("sis_journals"));
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
        self::assertSame($result, $f->tuition()->confirm($r["review_token"]));
        $period = $f->studentLedger()->overview("USD")["periods"][0];
        self::assertTrue(
            $f->studentLedger()->reconcile(new EntityId($period["id"]))["done"],
        );
    }

    private function issue(
        \SisFixture $f,
        EntityId $bill,
        ?int $amount = null,
    ): array {
        $current = $f->tuition()->bill($bill);
        $r = $f->tuition()->reviewInvoice(
            $bill,
            $current["version"],
            array_map(
                static fn(array $l): array => [
                    "key" => $l["key"],
                    "minor" => $amount ?? $l["minor"],
                ],
                $current["lines"],
            ),
            "2026-08-31",
            "Issue fictional reviewed invoice.",
        );
        return [$f->tuition()->confirm($r["review_token"]), $r];
    }

    private function drop(
        \SisFixture $f,
        EntityId $student,
        EntityId $section,
    ): array {
        $r = $f->drops->review($student, $section);
        return $f->drops->commit(
            $student,
            $section,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
    }

    public function testBracketBoundariesUseExactAcademicMilliCreditsAndHalfUpRounding(): void
    {
        [$g, , $schedule] = $this->configured();
        $d = $g->sis->tuition()->schedule($schedule)["definition"];
        foreach (
            [
                0 => 0,
                1 => 10,
                11999 => 119990,
                12000 => 100000,
                18000 => 100000,
                18001 => 100010,
                20000 => 120000,
            ] as $credits => $expected
        ) {
            self::assertSame(
                $expected,
                \Domain\Tuition\ValueObject\TuitionSchema::amount($d, $credits),
            );
        }
        $d["brackets"] = [
            [
                "from_milli" => 0,
                "through_milli" => 999999,
                "flat_minor" => 0,
                "rate_minor" => 500,
                "rate_from_milli" => 0,
            ],
        ];
        self::assertSame(
            1,
            \Domain\Tuition\ValueObject\TuitionSchema::amount($d, 1),
        );
        $d["brackets"][0]["from_milli"] = 1;
        $this->expectException(\InvalidArgumentException::class);
        \Domain\Tuition\ValueObject\TuitionSchema::definition($d);
    }

    public function testDiscountAndExcludedChargesAreFinalAndNewCoursesOnlyAddTheirIncrement(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 0)->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        [$invoice, $proof] = $this->issue($f, $id, 20000);
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
        self::assertSame(
            20000,
            $f->tuition()->invoice(new EntityId($invoice["id"]))["total_minor"],
        );
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        self::assertSame(
            $invoice,
            $f->tuition()->confirm($proof["review_token"]),
        );
        $f->service->register($student->id, $f->section(fee: 0)->id);
        self::assertSame(30000, $f->tuition()->bill($id)["pending_minor"]);
        [$excluded] = $this->issue($f, $id, 0);
        self::assertSame(
            0,
            $f->tuition()->invoice(new EntityId($excluded["id"]))[
                "total_minor"
            ],
        );
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
        self::assertCount(1, $f->sql->rows("sis_journals"));
    }

    public function testEarlyDropRemovesPendingWorkAndReversesOnlyIssuedCourseFees(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $section = $f->section(fee: 1000);
        $f->service->register($student->id, $section->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $this->drop($f, $student->id, $section->id);
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        $f->service->register($student->id, $section->id);
        [$issued] = $this->issue($f, $id);
        $account = new AccountId(
            $f->tuition()->invoice(new EntityId($issued["id"]))["account_id"],
        );
        $this->drop($f, $student->id, $section->id);
        self::assertSame(30000, $f->accounts->get($account)->balance()->minor);
        self::assertSame(30000, $f->tuition()->bill($id)["adjustment_minor"]);
        self::assertSame(
            31000,
            $f->tuition()->invoice(new EntityId($issued["id"]))["total_minor"],
        );
    }

    public function testAssignmentPrecedenceClearAndUnissuedRepricingRetainPublishedSources(): void
    {
        [$g, $student, $termSchedule] = $this->configured();
        $f = $g->sis;
        $d = $f->tuition()->schedule($termSchedule)["definition"];
        $d["brackets"][0]["rate_minor"] = 20000;
        $r = $f
            ->tuition()
            ->reviewSchedule(
                null,
                0,
                "Fictional program tuition",
                "USD",
                $g->income,
                $d["brackets"],
                "Approve fictional program tuition.",
            );
        $programSchedule = new EntityId(
            $f->tuition()->confirm($r["review_token"])["id"],
        );
        foreach (
            [
                ["program", $f->program, $programSchedule],
                ["student", $student->id, $termSchedule],
            ] as [$scope, $target, $schedule]
        ) {
            $r = $f
                ->tuition()
                ->reviewAssignment(
                    $scope,
                    $target,
                    $schedule,
                    0,
                    "Approve fictional tuition assignment.",
                );
            $f->tuition()->confirm($r["review_token"]);
        }
        self::assertSame(
            $termSchedule->value,
            $f->tuitionBilling()->assigned($student->id, $f->term)["schedule"][
                "id"
            ],
        );
        $f->service->register($student->id, $f->section(fee: 0)->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $r = $f
            ->tuition()
            ->reviewAssignment(
                "student",
                $student->id,
                null,
                1,
                "Clear fictional student override.",
            );
        $f->tuition()->confirm($r["review_token"]);
        self::assertSame(
            $programSchedule->value,
            $f->tuitionBilling()->assigned($student->id, $f->term)["schedule"][
                "id"
            ],
        );
        self::assertSame(30000, $f->tuition()->bill($id)["pending_minor"]);
        $bill = $f->tuition()->bill($id);
        $r = $f
            ->tuition()
            ->reviewReprice(
                $id,
                $bill["version"],
                "Review fictional current program pricing.",
            );
        $f->tuition()->confirm($r["review_token"]);
        self::assertSame(60000, $f->tuition()->bill($id)["pending_minor"]);
        $this->issue($f, $id);
        $this->expectException(\DomainException::class);
        $f->tuition()->reviewReprice(
            $id,
            $f->tuition()->bill($id)["version"],
            "Cannot rewrite an issued term.",
        );
    }

    public function testFailedAuditRollsBackInvoiceAccountAndJournalsWithOriginalReviewRetryable(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 1000)->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $bill = $f->tuition()->bill($id);
        $r = $f->tuition()->reviewInvoice(
            $id,
            $bill["version"],
            array_map(
                static fn(array $l): array => [
                    "key" => $l["key"],
                    "minor" => $l["minor"],
                ],
                $bill["lines"],
            ),
            "2026-08-31",
            "Review fictional atomic invoice.",
        );
        $tables = [
            "sis_history_events",
            "sis_student_accounts",
            "sis_journals",
            "sis_tuition_invoices",
            "sis_tuition_bills",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(
                new \RuntimeException("Fictional audit unavailable."),
            );
        try {
            $f->tuition($audit)->confirm($r["review_token"]);
            self::fail("Audit must be atomic.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                "Fictional audit unavailable.",
                $error->getMessage(),
            );
        }
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $f->tuition()->confirm($r["review_token"]);
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
    }

    public function testUnrelatedStaffCannotReadAnotherOwnersInvoiceAndRolesAreRecheckedOnRetry(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 0)->id);
        [$result, $r] = $this->issue(
            $f,
            TuitionLedger::billId($student->id, $f->term),
        );
        $original = $f->access->actor;
        $f->access->actor = $f->user();
        self::assertSame([], $f->tuition()->bills(false, "owner")["items"]);
        try {
            $f->tuition()->invoice(new EntityId($result["id"]), "owner");
            self::fail("Owner must match.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->actor = $original;
        $f->access->staff = false;
        $f->access->grants = [];
        $this->expectException(
            \Domain\Shared\Exception\AccessDeniedException::class,
        );
        $f->tuition()->confirm($r["review_token"]);
    }

    public function testChangedPendingWorkRejectsReviewedInvoiceAndLegacyBatchCannotBypassReview(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 0)->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $bill = $f->tuition()->bill($id);
        $r = $f->tuition()->reviewInvoice(
            $id,
            $bill["version"],
            array_map(
                static fn(array $l): array => [
                    "key" => $l["key"],
                    "minor" => $l["minor"],
                ],
                $bill["lines"],
            ),
            "2026-08-31",
            "Review fictional pending amounts.",
        );
        $f->service->register($student->id, $f->section(fee: 0)->id);
        try {
            $f->tuition()->confirm($r["review_token"]);
            self::fail("Changed pending work needs new review.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertTrue(true);
        }
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        $budget = new \Domain\Shared\EventSourcing\HistoryReadBudget();
        $q = $f->termFees()->quote($student->id, $f->term, [], $budget);
        $this->expectException(
            \Domain\Shared\Exception\ValidationException::class,
        );
        $f->termFees()->apply(
            $q,
            $f
                ->financialCommands()
                ->context(
                    $f->access->actor,
                    EntityId::generate(),
                    "Fictional batch cannot bypass invoice review.",
                ),
        );
    }

    public function testReviewUsesNewIncomeOnlyWhenExplicitlyRepricedAndKeepsAdjustedCreditFeeBasis(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $db = $f->connection->queryBuilder();
        $rules = new \Domain\Finance\Service\TermBillingService(
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $f->sql,
                \EventStoreFixture::framework($db),
                $f->transactions,
                \EventStoreFixture::financialWriter($db),
            ),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->financialCommands(),
        );
        $rules->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional laboratory fee",
            "fee",
            new \Domain\Finance\ValueObject\Money(500, "USD"),
            "Fictional laboratory fee approved.",
            \Domain\Finance\Enum\BillingBasis::RegisteredCredit,
        );
        $section = $f->section(fee: 0);
        $enrollment = $f->service->register($student->id, $section->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $bill = $f->tuition()->bill($id);
        $newIncome = $g->ledger->addAccount(
            "REV.NEW",
            "Fictional future income",
            "USD",
            "income",
        );
        $r = $f
            ->studentLedger()
            ->reviewMapping(
                "USD",
                "America/Los_Angeles",
                "AR",
                "Fictional receivable",
                $newIncome,
                $g->other,
                $g->other,
                [],
                [],
                "Fictional future income mapping.",
            );
        $f->studentLedger()->confirm($r["review_token"]);
        $r = $f->tuition()->reviewInvoice(
            $id,
            $bill["version"],
            array_map(
                static fn(array $l): array => [
                    "key" => $l["key"],
                    "minor" =>
                        $l["source"] === "credit_fee" ? 1000 : $l["minor"],
                ],
                $bill["lines"],
            ),
            "2026-08-31",
            "Discount fictional laboratory fee.",
        );
        $issued = $f->tuition()->confirm($r["review_token"]);
        $account = new AccountId(
            $f->tuition()->invoice(new EntityId($issued["id"]))["account_id"],
        );
        $credit = array_values(
            array_filter(
                $f->accounts->get($account)->entries(),
                static fn(array $l): bool => isset($l["registration_price"]),
            ),
        )[0];
        self::assertSame(1000, $credit["minor"]);
        self::assertSame(500, $credit["registration_price"]["rate_minor"]);
        self::assertSame(3000, $credit["registration_price"]["credits_milli"]);
        foreach ($f->sql->rows("sis_journals") as $row) {
            $journal = $f
                ->journalRepository()
                ->get(new \Domain\Finance\ValueObject\JournalId($row["id"]));
            $income = array_values(
                array_filter(
                    $journal->lines(),
                    static fn($l): bool => $l->amount->minor < 0,
                ),
            )[0];
            self::assertSame($g->income->value, $income->accountId->value);
        }
        $this->drop($f, $student->id, $section->id);
        self::assertSame(30000, $f->accounts->get($account)->balance()->minor);
        $period = new EntityId(
            $f->studentLedger()->overview("USD")["periods"][0]["id"],
        );
        self::assertTrue($f->studentLedger()->reconcile($period)["done"]);
    }

    public function testSectionReplacementReviewsOnlyThePendingReplacementIncrement(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $a = $f->section(fee: 1000);
        $b = $f->section(fee: 1500);
        $f->sql->update(
            "sis_sections",
            ["course_id" => $a->courseId->value],
            ["id" => $b->id->value],
        );
        $f->service->register($student->id, $a->id, true);
        $selection = new \Domain\Registration\ValueObject\SectionChangeSelection(
            $student->id,
            $a->id,
            $b->id,
        );
        $service = $f->sectionChanges();
        $r = $service->review($selection, true);
        self::assertSame("pending", $r["financial"]["mode"]);
        self::assertSame(31500, $r["financial"]["new_charge_minor"]);
        self::assertSame(0, $r["financial"]["net_change_minor"]);
        $service->commit(
            new EntityId($r["operation_id"]),
            $selection,
            $r["revision"],
            $r["review_token"],
            true,
        );
        self::assertSame(
            31500,
            $f->tuition()->bill(TuitionLedger::billId($student->id, $f->term))[
                "pending_minor"
            ],
        );
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }

    public function testMissingAccountingPeriodCannotPartiallyIssueAndZeroInvoiceNeedsNoAccount(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $f->service->register($student->id, $f->section(fee: 0)->id);
        $id = TuitionLedger::billId($student->id, $f->term);
        $bill = $f->tuition()->bill($id);
        $f->clock->time = new \DateTimeImmutable("2026-09-01T12:00:00-07:00");
        $r = $f
            ->tuition()
            ->reviewInvoice(
                $id,
                $bill["version"],
                [["key" => "tuition", "minor" => 30000]],
                "2026-09-30",
                "Review fictional invoice without period.",
            );
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->tuition()->confirm($r["review_token"]);
            self::fail("Missing period accepted.");
        } catch (\Domain\Finance\Exception\AccountingControlException) {
            self::assertTrue(true);
        }
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        self::assertSame([], $f->sql->rows("sis_tuition_invoices"));
        $zero = $f
            ->tuition()
            ->reviewInvoice(
                $id,
                $bill["version"],
                [["key" => "tuition", "minor" => 0]],
                "2026-09-30",
                "Explicitly exclude fictional tuition.",
            );
        $result = $f->tuition()->confirm($zero["review_token"]);
        self::assertNull(
            $f->tuition()->invoice(new EntityId($result["id"]))["account_id"],
        );
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        self::assertSame(0, $f->tuition()->bill($id)["pending_minor"]);
    }

    public function testCeusDoNotIncreaseAcademicTuitionAndAuditorsRetainOnlyTheirAcceptedFee(): void
    {
        [$g, $student] = $this->configured();
        $s = $g->sis;
        require_once dirname(__DIR__) . "/Support/OfferingCreditFixture.php";
        $offering = new \Tests\Support\OfferingCreditFixture(
            sis: $s,
            fee: 1000,
        );
        $policies = new \Domain\Registration\Service\CourseAuditingPolicyService(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository(
                $s->sql,
            ),
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $current = $policies->current($offering->section->id);
        $policies->save(
            $offering->section->id,
            new \Domain\Registration\ValueObject\CourseAuditing(2500, true),
            $current["revision"],
        );
        $course = $offering->section->courseId;
        $offering->editor->continuingEducation(
            "courses",
            $course,
            new \Domain\Registration\ValueObject\OfferingCeuChoice(
                "fixed",
                1250,
            ),
            $offering->editor->continuingEducationSettings("courses", $course)[
                "revision"
            ],
        );
        $offering->bind();
        $offering->select($offering->publish());
        $s->service->register($student->id, $offering->section->id, true);
        $standard = $s
            ->tuition()
            ->bill(TuitionLedger::billId($student->id, $s->term));
        self::assertSame(3000, $standard["credits_milli"]);
        self::assertSame(31000, $standard["pending_minor"]);
        $auditor = $s->student();
        $s->service->register(
            $auditor->id,
            $offering->section->id,
            true,
            basis: \Domain\Registration\Enum\EnrollmentBasis::Audit,
        );
        $audit = $s
            ->tuition()
            ->bill(TuitionLedger::billId($auditor->id, $s->term));
        self::assertSame(0, $audit["credits_milli"]);
        self::assertSame(2500, $audit["pending_minor"]);
        self::assertSame([], $s->sql->rows("sis_student_accounts"));
    }

    public function testGroupedRegistrationPricesTheWholeCreditBracketOnceAndKeepsItsOriginalReceipt(): void
    {
        [$g, $student] = $this->configured();
        $f = $g->sis;
        $sections = [];
        for ($i = 0; $i < 4; $i++) {
            $sections[] = $f->section(fee: 0)->id;
        }
        $selection = new \Domain\Registration\ValueObject\RegistrationPlanSelection($student->id, ...$sections);
        $service = $f->registrationBatches();
        $r = $service->reviewPlan($selection, true);
        self::assertTrue($r['can_confirm']);
        self::assertSame(100000, $r['totals']['USD']);
        $result = $service->commitPlan(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true,
        );
        self::assertSame(4, $result['created']);
        $bill = $f->tuition()->bill(TuitionLedger::billId($student->id, $f->term));
        self::assertSame(12000, $bill['credits_milli']);
        self::assertSame(100000, $bill['pending_minor']);
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
        $events = $f->sql->rows('sis_history_events');
        $f->clock->time = $f->clock->time->modify('+21 minutes');
        self::assertSame($result, $service->commitPlan(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true,
        ));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }
}
