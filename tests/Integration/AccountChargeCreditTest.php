<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\AccountEntryHistory;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class AccountChargeCreditTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(SisFixture $s, ?AuditTrail $audit = null): AccountEntryReviewService
    {
        return new AccountEntryReviewService(
            $s->accounts,
            $s->access,
            $s->transactions,
            $s->financialCommands(),
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    private function account(SisFixture $s): AccountId
    {
        return $s->finance()->open($s->student()->id, $s->term, "USD")->aggregateId();
    }

    private function post(SisFixture $s, AccountId $id, EntryType $type, int $minor = 1000): EntityId
    {
        $op = EntityId::generate();
        $s->finance()->post(
            $id,
            $op,
            $type,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional source.",
        );
        return $op;
    }

    private function credit(SisFixture $s, AccountId $id, EntityId $source, int $minor = 200): array
    {
        $service = $this->service($s);
        $review = $service->reviewChargeCredit(
            $id,
            $source,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional charge credit.",
        );
        return $service->commitChargeCredit($id, $source, $review["review_token"]);
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_account_balances",
            "sis_student_accounts",
            "sis_audit",
        ]);
    }

    public function testPartialAndFullCreditsKeepOriginalChargeAndNetReportingTotals(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $events = $s->sql->rows("sis_history_events");
        $service = $this->service($s);
        $review = $service->reviewChargeCredit(
            $id,
            $source,
            new Money(200, "USD"),
            2,
            "Fictional approved waiver <script>literal</script>.",
        );
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame(1000, $review["charge_minor"]);
        self::assertSame(0, $review["credited_minor"]);
        self::assertSame(800, $review["remaining_charge_minor"]);
        self::assertSame(800, $review["resulting_balance_minor"]);
        $first = $service->commitChargeCredit($id, $source, $review["review_token"]);
        $creditId = new EntityId($first["operation_id"]);
        $root = $s->accounts->get($id);
        self::assertSame(1000, $root->entry($source)["minor"]);
        self::assertFalse($root->entry($source)["reversed"]);
        self::assertSame($source->value, $root->entry($creditId)["source_operation_id"]);
        self::assertSame("charge_credit", $root->entry($creditId)["type"]);
        self::assertFalse($root->canCorrectAmount($source));
        self::assertFalse($root->canCorrectAmount($creditId));
        self::assertFalse($root->canCorrectPaymentDetails($creditId));
        $this->credit($s, $id, $source, 800);
        $root = $s->accounts->get($id);
        self::assertSame(0, $root->balance()->minor);
        self::assertFalse($root->canCreditCharge($source));
        self::assertSame(1000, $root->entry($source)["credited_minor"]);
        self::assertSame(0, $root->entry($source)["remaining_charge_minor"]);
        $projection = $s->sql->rows("sis_account_balances")[0];
        self::assertSame(0, (int) $projection["charges_minor"]);
        self::assertSame(0, (int) $projection["payments_minor"]);
        self::assertSame(0, (int) $projection["refunds_minor"]);
        self::assertSame($events, array_slice($s->sql->rows("sis_history_events"), 0, count($events)));
        self::assertStringNotContainsString(
            "approved waiver",
            json_encode($s->sql->rows("sis_history_events")),
        );
        self::assertStringNotContainsString("approved waiver", json_encode($s->sql->rows("sis_audit")));
    }

    public function testAChargeCreditCanCreateRefundableAccountCreditWithoutRecordingAPayment(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $this->post($s, $id, EntryType::Payment);
        $this->credit($s, $id, $source, 250);
        self::assertSame(-250, $s->accounts->get($id)->balance()->minor);
        $this->post($s, $id, EntryType::Refund, 250);
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
        $row = $s->sql->rows("sis_account_balances")[0];
        self::assertSame(750, (int) $row["charges_minor"]);
        self::assertSame(1000, (int) $row["payments_minor"]);
        self::assertSame(250, (int) $row["refunds_minor"]);
    }

    public function testReversingACreditRestoresOnlyItsChargeAmountAndReenablesCorrection(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $credit = new EntityId($this->credit($s, $id, $source)["operation_id"]);
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $credit, 3, "Fictional credit restored.");
        self::assertSame(1000, $review["resulting_balance_minor"]);
        $service->commitReversal($id, $credit, $review["review_token"]);
        self::assertTrue($s->accounts->get($id)->canCorrectAmount($source));
        self::assertSame(0, $s->accounts->get($id)->entry($source)["credited_minor"]);
        self::assertSame(1000, (int) $s->sql->rows("sis_account_balances")[0]["charges_minor"]);
    }

    public function testChargeReversalAtomicallyReversesOnlyItsStillActiveCredits(): void
    {
        foreach ([200, 1000] as $minor) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Charge);
            $alreadyReversed = new EntityId($this->credit($s, $id, $source, 100)["operation_id"]);
            $s->finance()->reverse($id, $alreadyReversed, 3, "Fictional earlier credit reversal.");
            $active = new EntityId($this->credit($s, $id, $source, $minor)["operation_id"]);
            $service = $this->service($s);
            $review = $service->reviewReversal($id, $source, 5, "Fictional source charge reversal.");
            self::assertSame(1, $review["reversed_credit_count"]);
            self::assertSame($minor, $review["reversed_credit_minor"]);
            self::assertSame(0, $review["resulting_balance_minor"]);
            $events = $s->sql->rows("sis_history_events");
            $service->commitReversal($id, $source, $review["review_token"]);
            self::assertCount(count($events) + 1, $s->sql->rows("sis_history_events"));
            $root = $s->accounts->get($id);
            self::assertSame(0, $root->balance()->minor);
            self::assertTrue($root->entry($active)["reversed"]);
            self::assertSame($source->value, $root->entry($active)["reversed_with_operation_id"]);
            self::assertArrayNotHasKey("reversed_with_operation_id", $root->entry($alreadyReversed));
            $history = EventStoreFixture::history($s->connection->queryBuilder())->page(
                new StreamReference($id, "student_account"),
            )->events;
            self::assertSame(
                [$active->value],
                $history[array_key_last($history)]->event->metadata()["reversed_credit_ids"],
            );
            self::assertSame(0, (int) $s->sql->rows("sis_account_balances")[0]["charges_minor"]);
        }
    }

    public function testEarlyCourseDropReversesDiscountAndOriginalChargeWithoutChangingOtherBills(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $enrollment = $s->service->register($student->id, $section->id);
        $id = new AccountId(
            $s->sql->one("sis_student_accounts", [
                "student_id" => $student->id->value,
                "term_id" => $s->term->value,
            ])["id"],
        );
        $credit = new EntityId($this->credit($s, $id, $enrollment->id, 2000)["operation_id"]);
        $this->post($s, $id, EntryType::Charge, 500);
        $review = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($review["enrollment_id"]),
            $review["version"],
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
        $root = $s->accounts->get($id);
        self::assertSame(500, $root->balance()->minor);
        self::assertTrue($root->entry($enrollment->id)["reversed"]);
        self::assertTrue($root->entry($credit)["reversed"]);
        self::assertSame($enrollment->id->value, $root->entry($credit)["reversed_with_operation_id"]);
        self::assertSame(500, (int) $s->sql->rows("sis_account_balances")[0]["charges_minor"]);
    }

    public function testAcceptedCreditRetryPreservesOriginalResultAfterSourceReversalAndExpiry(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $service = $this->service($s);
        $review = $service->reviewChargeCredit($id, $source, new Money(200, "USD"), 2, "Fictional credit.");
        $accepted = $service->commitChargeCredit($id, $source, $review["review_token"]);
        $s->finance()->reverse($id, $source, 3, "Fictional source reversal.");
        $s->clock->time = $s->clock->now()->modify("+1 day");
        $facts = $this->facts($s);
        self::assertSame($accepted, $service->commitChargeCredit($id, $source, $review["review_token"]));
        self::assertSame($facts, $this->facts($s));
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
    }

    public function testTwoReviewsCannotOverCreditTheSameRemainingCharge(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $service = $this->service($s);
        $first = $service->reviewChargeCredit(
            $id,
            $source,
            new Money(600, "USD"),
            2,
            "Fictional first credit.",
        );
        $second = $service->reviewChargeCredit(
            $id,
            $source,
            new Money(600, "USD"),
            2,
            "Fictional competing credit.",
        );
        $service->commitChargeCredit($id, $source, $first["review_token"]);
        $facts = $this->facts($s);
        try {
            $service->commitChargeCredit($id, $source, $second["review_token"]);
            self::fail("Stale overlapping credit accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($facts, $this->facts($s));
        }
        self::assertSame(400, $s->accounts->get($id)->balance()->minor);
    }

    public function testUnsupportedSourcesAmountsCurrenciesAndRawUnlinkedCreditsNeverWrite(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $payment = $this->post($s, $id, EntryType::Payment, 2000);
        $refund = $this->post($s, $id, EntryType::Refund, 1);
        $service = $this->service($s);
        $invalid = [
            [$payment, new Money(1, "USD")],
            [$refund, new Money(1, "USD")],
            [EntityId::generate(), new Money(1, "USD")],
            [$source, new Money(0, "USD")],
            [$source, new Money(-1, "USD")],
            [$source, new Money(1001, "USD")],
            [$source, new Money(1, "EUR")],
        ];
        $facts = $this->facts($s);
        foreach ($invalid as [$op, $amount]) {
            try {
                $service->reviewChargeCredit($id, $op, $amount, 4, "Fictional invalid credit.");
                self::fail("Invalid credit accepted.");
            } catch (ValidationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        try {
            $s->finance()->post(
                $id,
                EntityId::generate(),
                EntryType::ChargeCredit,
                new Money(1, "USD"),
                4,
                "Fictional unlinked credit.",
            );
            self::fail("Raw unlinked credit accepted.");
        } catch (DomainException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testCreditHistoryRetainsSourceAndCascadedReversalWithFrozenPages(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $credit = new EntityId($this->credit($s, $id, $source)["operation_id"]);
        $s->finance()->reverse($id, $source, 3, "Fictional linked reversal.");
        $history = new AccountEntryHistory(
            EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            new class implements ActorDirectory {
                public function names(array $actors): array
                {
                    return [];
                }
            },
            $s->audit,
        );
        $page = $history->page($id, $credit);
        self::assertSame(["reversed", "credited", "posted"], array_column($page["items"], "action"));
        self::assertSame([$credit->value], $page["items"][0]["reversed_credit_ids"]);
        self::assertSame($source->value, $page["items"][1]["source_operation_id"]);
        self::assertSame(200, $page["items"][1]["amount_minor"]);
        $captured = $history->page($id, $source, throughPosition: 3);
        self::assertSame(["credited", "posted"], array_column($captured["items"], "action"));
        self::assertFalse($captured["entry"]["reversed"]);
    }

    public function testAuditFailureAndLateAuthorityLossRollBackEventBalancesAndReceiptTogether(): void
    {
        foreach (["audit", "authority"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Charge);
            $review = $this->service($s)->reviewChargeCredit(
                $id,
                $source,
                new Money(100, "USD"),
                2,
                "Fictional reviewed credit.",
            );
            $audit = $this->createMock(AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($s, $failure): void {
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional required audit failure.");
                    }
                    $s->access->staff = false;
                    $s->access->grants = ["sis:finance:post"];
                });
            $facts = $this->facts($s);
            try {
                $this->service($s, $audit)->commitChargeCredit($id, $source, $review["review_token"]);
                self::fail("Unauthorized or unaudited credit accepted.");
            } catch (AccessDeniedException | RuntimeException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testLateCourseDropKeepsTheChargeAndItsApprovedCredit(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $enrollment = $s->service->register($student->id, $section->id);
        $id = new AccountId(
            $s->sql->one("sis_student_accounts", [
                "student_id" => $student->id->value,
                "term_id" => $s->term->value,
            ])["id"],
        );
        $credit = new EntityId($this->credit($s, $id, $enrollment->id, 2000)["operation_id"]);
        $s->clock->time = new \DateTimeImmutable("2026-08-16T00:00:00-07:00");
        $review = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($review["enrollment_id"]),
            $review["version"],
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
        $root = $s->accounts->get($id);
        self::assertSame(8000, $root->balance()->minor);
        self::assertFalse($root->entry($enrollment->id)["reversed"]);
        self::assertFalse($root->entry($credit)["reversed"]);
    }

    public function testNetChargeReportsReadTheReconstructibleProjectionWithoutAppendingEvents(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $this->credit($s, $id, $source);
        $before = $this->facts($s);
        $columns = array_map(
            static fn(string $field): array => ["field" => $field, "label" => $field, "aggregate" => "none"],
            ["currency", "charges", "payments", "refunds", "balance"],
        );
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            ["dataset" => "accounts", "columns" => $columns, "sort" => []],
            \Infrastructure\Reporting\AcademicReportSchema::datasets()["accounts"],
        );
        $rows = new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql)->execute($query, 0);
        self::assertSame(8.0, $rows[0]["c1"]);
        self::assertSame(8.0, $rows[0]["c4"]);
        self::assertSame($before, $this->facts($s));
    }

    public function testMissingLinkedCreditEvidenceCannotBeReplayedAsAValidChargeReversal(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $this->credit($s, $id, $source);
        $s->finance()->reverse($id, $source, 3, "Fictional linked reversal.");
        $stored = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($id, "student_account"),
        )->events;
        $events = array_map(static fn($e) => $e->event, $stored);
        $last = $events[array_key_last($events)];
        $metadata = $last->metadata();
        unset($metadata["reversed_credit_ids"]);
        $events[array_key_last($events)] = $last->withMetadata($metadata);
        $this->expectException(DomainException::class);
        \Domain\Finance\StudentAccount::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($id, $events),
        );
    }

    public function testCreditCannotOverflowTheSupportedAccountBalance(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge, 1);
        $this->post($s, $id, EntryType::Payment, Money::MAX_MINOR);
        $this->post($s, $id, EntryType::Payment, 1);
        $facts = $this->facts($s);
        try {
            $this->service($s)->reviewChargeCredit(
                $id,
                $source,
                new Money(1, "USD"),
                4,
                "Fictional excessive account credit.",
            );
            self::fail("An unsupported balance was accepted.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testEarlyDropWithAnAlreadyPaidCreditAndIssuedRefundKeepsOnlyActualUnreturnedMoney(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $enrollment = $s->service->register($student->id, $section->id);
        $id = new AccountId(
            $s->sql->one("sis_student_accounts", [
                "student_id" => $student->id->value,
                "term_id" => $s->term->value,
            ])["id"],
        );
        $this->post($s, $id, EntryType::Payment, 10000);
        $this->credit($s, $id, $enrollment->id, 2000);
        $this->post($s, $id, EntryType::Refund, 2000);
        $review = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($review["enrollment_id"]),
            $review["version"],
            new EntityId($review["operation_id"]),
            $review["revision"],
            $review["effective_on"],
        );
        self::assertSame(-8000, $s->accounts->get($id)->balance()->minor);
        $projection = $s->sql->rows("sis_account_balances")[0];
        self::assertSame(0, (int) $projection["charges_minor"]);
        self::assertSame(10000, (int) $projection["payments_minor"]);
        self::assertSame(2000, (int) $projection["refunds_minor"]);
    }

    public function testIndependentGrantsWrongPurposeAndExpiryProtectCreditConfirmation(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Charge);
        $service = $this->service($s);
        $review = $service->reviewChargeCredit(
            $id,
            $source,
            new Money(100, "USD"),
            2,
            "Fictional approved credit.",
        );
        $facts = $this->facts($s);
        foreach ([["sis:finance:read"], ["sis:finance:post"], ["sis:registration:manage"]] as $grants) {
            $s->access->staff = false;
            $s->access->grants = $grants;
            try {
                $service->commitChargeCredit($id, $source, $review["review_token"]);
                self::fail("Both finance grants are required.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $s->access->staff = true;
        $s->access->grants = [];
        try {
            $service->commitCorrection($id, $source, $review["review_token"]);
            self::fail("Credit proof changed original charge.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $s->clock->time = $s->clock->now()->modify("+20 minutes");
        try {
            $service->commitChargeCredit($id, $source, $review["review_token"]);
            self::fail("Expired credit accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }
}
