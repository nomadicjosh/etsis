<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\Service\AccountEntryHistory;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Finance\ValueObject\RefundAllocation;
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

final class PaymentRefundTest extends TestCase
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

    private function refund(SisFixture $s, AccountId $id, EntityId $payment, int $minor = 200): EntityId
    {
        $service = $this->service($s);
        $review = $service->reviewRefund(
            $id,
            $payment,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional issued refund.",
        );
        return new EntityId($service->commitRefund($id, $payment, $review["review_token"])["operation_id"]);
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_student_accounts",
            "sis_account_balances",
            "sis_audit",
        ]);
    }

    public function testReviewedRefundRetainsItsPaymentAndSeparateIncomingOutgoingReceiptDetails(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = EntityId::generate();
        $incoming = new PaymentDetails(PaymentMethod::Card, "FICT-INCOMING");
        $outgoing = new PaymentDetails(PaymentMethod::Check, "FICT-OUTGOING");
        $s->finance()->post(
            $id,
            $payment,
            EntryType::Payment,
            new Money(1000, "USD"),
            1,
            "Fictional receipt.",
            $incoming,
        );
        $events = $s->sql->rows("sis_history_events");
        $service = $this->service($s);
        $review = $service->reviewRefund(
            $id,
            $payment,
            new Money(250, "USD"),
            2,
            "Fictional approved refund <script>literal</script>.",
            $outgoing,
        );
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame($incoming->values(), $review["allocation_receipts"][0]["payment_details"]);
        self::assertSame($outgoing->values(), $review["payment_details"]);
        self::assertSame(750, $review["remaining_payment_minor"]);
        self::assertSame(-750, $review["resulting_balance_minor"]);
        $result = $service->commitRefund($id, $payment, $review["review_token"]);
        $op = new EntityId($result["operation_id"]);
        $root = $s->accounts->get($id);
        self::assertNotSame($payment->value, $op->value);
        self::assertSame("refund", $root->entry($op)["type"]);
        self::assertSame($payment->value, $root->entry($op)["refund_allocations"][0]["payment_operation_id"]);
        self::assertSame(
            ["linked_refund_minor" => 250, "linked_refund_count" => 1, "remaining_refundable_minor" => 750],
            $root->paymentRefundSummary($payment),
        );
        self::assertSame($incoming->values(), $root->paymentDetails($payment)->values());
        self::assertSame($outgoing->values(), $root->paymentDetails($op)->values());
        self::assertSame(1000, $root->entry($payment)["minor"]);
        self::assertArrayNotHasKey("payment_details", $root->entry($op));
        $history = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($id, "student_account"),
        )->events;
        $last = $history[array_key_last($history)];
        self::assertSame(
            $payment->value,
            $last->event->metadata()["refund_allocations"][0]["payment_operation_id"],
        );
        self::assertSame(hash("sha256", $review["review_token"]), $last->event->metadata()["review_hash"]);
        self::assertStringNotContainsString("FICT-INCOMING", json_encode($s->sql->rows("sis_history_events")));
        self::assertStringNotContainsString("FICT-OUTGOING", json_encode($s->sql->rows("sis_audit")));
    }

    public function testRefundMustFitBothSourcePaymentAndAccountCredit(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment);
        $second = $this->post($s, $id, EntryType::Payment);
        $this->refund($s, $id, $first, 800);
        $facts = $this->facts($s);
        try {
            $this->refund($s, $id, $first, 201);
            self::fail("Source payment cap was bypassed.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $this->refund($s, $id, $first, 200);
        self::assertFalse($s->accounts->get($id)->canRefundPayment($first));
        $this->post($s, $id, EntryType::Charge, 500);
        try {
            $this->refund($s, $id, $second, 501);
            self::fail("Account credit cap was bypassed.");
        } catch (ValidationException) {
            self::assertSame(-500, $s->accounts->get($id)->balance()->minor);
        }
        $this->refund($s, $id, $second, 500);
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
        $projection = $s->sql->rows("sis_account_balances")[0];
        self::assertSame(2000, (int) $projection["payments_minor"]);
        self::assertSame(1500, (int) $projection["refunds_minor"]);
        self::assertSame(500, (int) $projection["charges_minor"]);
    }

    public function testPaymentAndRefundAmountCorrectionsKeepOneRefundAllowance(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment, 800);
        $service = $this->service($s);
        $correction = $service->reviewCorrection(
            $id,
            $payment,
            new Money(1250, "USD"),
            3,
            "Fictional payment amount correction.",
        );
        $current = new EntityId(
            $service->commitCorrection($id, $payment, $correction["review_token"])["operation_id"],
        );
        $root = $s->accounts->get($id);
        self::assertFalse($root->canRefundPayment($payment));
        self::assertSame(450, $root->paymentRefundSummary($current)["remaining_refundable_minor"]);
        self::assertSame($payment->value, $root->entry($refund)["refund_allocations"][0]["payment_operation_id"]);
        $this->refund($s, $id, $current, 400);
        $refundCorrection = $service->reviewCorrection(
            $id,
            $refund,
            new Money(850, "USD"),
            5,
            "Fictional refund amount correction.",
        );
        $replacement = new EntityId(
            $service->commitCorrection($id, $refund, $refundCorrection["review_token"])["operation_id"],
        );
        $root = $s->accounts->get($id);
        self::assertSame(
            $payment->value,
            $root->entry($replacement)["refund_allocations"][0]["payment_operation_id"],
        );
        self::assertSame(1250, $root->paymentRefundSummary($current)["linked_refund_minor"]);
        self::assertSame(2, $root->paymentRefundSummary($current)["linked_refund_count"]);
        self::assertSame(0, $root->balance()->minor);
    }

    public function testSourceCapAlsoAppliesToIncreasingALinkedRefundAmount(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $this->post($s, $id, EntryType::Payment, 2000);
        $refund = $this->refund($s, $id, $payment, 800);
        $this->refund($s, $id, $payment, 100);
        $facts = $this->facts($s);
        try {
            $this->service($s)->reviewCorrection(
                $id,
                $refund,
                new Money(901, "USD"),
                5,
                "Fictional excessive refund correction.",
            );
            self::fail("A corrected refund exceeded its source.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testPaymentReversalRetainsIssuedRefundsAndRefundReversalRestoresAllowance(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment);
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $payment, 3, "Fictional payment reversal.");
        self::assertSame(200, $review["linked_refund_minor"]);
        self::assertSame(1, $review["linked_refund_count"]);
        $service->commitReversal($id, $payment, $review["review_token"]);
        $root = $s->accounts->get($id);
        self::assertSame(200, $root->balance()->minor);
        self::assertFalse($root->entry($refund)["reversed"]);
        self::assertFalse($root->canRefundPayment($payment));
        $reversal = $service->reviewReversal($id, $refund, 4, "Fictional refund reversed.");
        $service->commitReversal($id, $refund, $reversal["review_token"]);
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
        self::assertSame(0, $s->accounts->get($id)->paymentRefundSummary($payment)["linked_refund_minor"]);
    }

    public function testReducingARefundRemainsPossibleAfterItsPaymentWasReducedOrReversed(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment, 800);
        $s->finance()->reverse($id, $payment, 3, "Fictional invalid original payment.");
        $service = $this->service($s);
        $review = $service->reviewCorrection(
            $id,
            $refund,
            new Money(600, "USD"),
            4,
            "Fictional actual refund was smaller.",
        );
        $service->commitCorrection($id, $refund, $review["review_token"]);
        self::assertSame(600, $s->accounts->get($id)->balance()->minor);
        self::assertSame(600, $s->accounts->get($id)->paymentRefundSummary($payment)["linked_refund_minor"]);
        self::assertSame(0, $s->accounts->get($id)->paymentRefundSummary($payment)["remaining_refundable_minor"]);
    }

    public function testEarlierUnlinkedRefundsRemainUnknownAndAccountCreditStillLimitsNewRefunds(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $unknown = $this->post($s, $id, EntryType::Refund, 300);
        $events = $s->sql->rows("sis_history_events");
        $service = $this->service($s);
        $review = $service->reviewRefund($id, $payment, new Money(200, "USD"), 3, "Fictional linked refund.");
        self::assertSame(300, $review["unlinked_refund_minor"]);
        self::assertSame(0, $review["linked_refund_minor"]);
        self::assertSame(800, $review["remaining_payment_minor"]);
        self::assertSame(500, $review["remaining_credit_minor"]);
        $service->commitRefund($id, $payment, $review["review_token"]);
        self::assertSame($events, array_slice($s->sql->rows("sis_history_events"), 0, count($events)));
        self::assertArrayNotHasKey("refund_allocations", $s->accounts->get($id)->entry($unknown));
    }

    public function testConcurrentRefundsAndAcceptedRetriesCannotSpendTheSamePaymentTwice(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $first = $service->reviewRefund($id, $payment, new Money(600, "USD"), 2, "Fictional first refund.");
        $second = $service->reviewRefund($id, $payment, new Money(600, "USD"), 2, "Fictional concurrent refund.");
        $accepted = $service->commitRefund($id, $payment, $first["review_token"]);
        try {
            $service->commitRefund($id, $payment, $second["review_token"]);
            self::fail("Stale competing refund accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(-400, $s->accounts->get($id)->balance()->minor);
        }
        $s->finance()->reverse($id, new EntityId($accepted["operation_id"]), 3, "Fictional refund reversal.");
        $s->clock->time = $s->clock->now()->modify("+1 day");
        $facts = $this->facts($s);
        self::assertSame($accepted, $service->commitRefund($id, $payment, $first["review_token"]));
        self::assertSame($facts, $this->facts($s));
        self::assertSame(
            1000,
            $s->accounts->get($id)->paymentRefundSummary($payment)["remaining_refundable_minor"],
        );
    }

    public function testWrongProofScopePurposeActorAndExpiryWriteNothing(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewRefund($id, $payment, new Money(200, "USD"), 2, "Fictional refund.");
        $facts = $this->facts($s);
        try {
            $service->commitPosting($id, $review["review_token"]);
            self::fail("Linked refund proof was used as an ordinary posting.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $invalidScopes = [[new AccountId(), $payment], [$id, EntityId::generate()]];
        foreach ($invalidScopes as [$account, $source]) {
            try {
                $service->commitRefund($account, $source, $review["review_token"]);
                self::fail("Wrong refund scope accepted.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $actor = $s->access->actor;
        $s->access->actor = EntityId::generate();
        try {
            $service->commitRefund($id, $payment, $review["review_token"]);
            self::fail("Wrong actor accepted.");
        } catch (AccessDeniedException) {
            self::assertSame($facts, $this->facts($s));
        }
        $s->access->actor = $actor;
        $s->clock->time = $s->clock->now()->modify("+20 minutes");
        try {
            $service->commitRefund($id, $payment, $review["review_token"]);
            self::fail("Expired refund accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testIndependentGrantsAndRequiredAuditProtectTheEntireRefundTransaction(): void
    {
        foreach (["read_only", "post_only", "audit", "late_authority"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $payment = $this->post($s, $id, EntryType::Payment);
            $review = $this->service($s)->reviewRefund(
                $id,
                $payment,
                new Money(200, "USD"),
                2,
                "Fictional reviewed refund.",
            );
            $audit = $this->createMock(AuditTrail::class);
            if (in_array($failure, ["audit", "late_authority"], true)) {
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
            } else {
                $audit->expects(self::never())->method("record");
                $s->access->staff = false;
                $s->access->grants = [$failure === "read_only" ? "sis:finance:read" : "sis:finance:post"];
            }
            $facts = $this->facts($s);
            try {
                $this->service($s, $audit)->commitRefund($id, $payment, $review["review_token"]);
                self::fail("Unaudited or unauthorized refund accepted.");
            } catch (AccessDeniedException | RuntimeException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testHistoryIncludesPaymentRefundCorrectionsAndPreservedLinksAtTheCapturedPosition(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment);
        $service = $this->service($s);
        $review = $service->reviewCorrection($id, $refund, new Money(250, "USD"), 3, "Fictional corrected refund.");
        $current = new EntityId($service->commitCorrection($id, $refund, $review["review_token"])["operation_id"]);
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
        $page = $history->page($id, $payment);
        self::assertSame(["corrected", "posted", "posted"], array_column($page["items"], "action"));
        self::assertSame($payment->value, $page["items"][0]["refund_allocations"][0]["payment_operation_id"]);
        self::assertSame($current->value, $page["items"][0]["operation_id"]);
        $captured = $history->page($id, $payment, throughPosition: 2);
        self::assertCount(1, $captured["items"]);
        self::assertSame(0, $captured["entry"]["linked_refund_minor"]);
    }

    public function testVerifiedAutomaticPaymentsCanBeRefundedWhileNonPaymentSourcesAreRejected(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = EntityId::generate();
        $root = $s->accounts->get($id);
        $root->post($payment, EntryType::Payment, new Money(1000, "USD"), $s->access->actor);
        $s->saveAccount($root);
        self::assertFalse($s->accounts->get($id)->canCorrectAmount($payment));
        $refund = $this->refund($s, $id, $payment);
        $charge = $this->post($s, $id, EntryType::Charge, 1);
        $invalid = [
            [$refund, new Money(1, "USD")],
            [$charge, new Money(1, "USD")],
            [EntityId::generate(), new Money(1, "USD")],
            [$payment, new Money(1, "EUR")],
        ];
        $facts = $this->facts($s);
        foreach ($invalid as [$source, $amount]) {
            try {
                $this->service($s)->reviewRefund($id, $source, $amount, 4, "Fictional invalid refund.");
                self::fail("Invalid source or currency accepted.");
            } catch (ValidationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testARefundCorrectionCannotLoseItsRecordedPaymentLinkDuringReplay(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment);
        $service = $this->service($s);
        $review = $service->reviewCorrection($id, $refund, new Money(250, "USD"), 3, "Fictional corrected refund.");
        $service->commitCorrection($id, $refund, $review["review_token"]);
        $stored = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($id, "student_account"),
        )->events;
        $events = array_map(static fn($event) => $event->event, $stored);
        $last = $events[array_key_last($events)];
        $metadata = $last->metadata();
        unset($metadata["refund_allocations"]);
        $events[array_key_last($events)] = $last->withMetadata($metadata);
        $this->expectException(ValidationException::class);
        \Domain\Finance\StudentAccount::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($id, $events),
        );
    }

    public function testRawPostingRetriesCannotRetargetTheRecordedPaymentLink(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $payment);
        $root = $s->accounts->get($id);
        $root->post(
            $refund,
            EntryType::Refund,
            new Money(200, "USD"),
            $s->access->actor,
            staffRecorded: true,
            allocation: RefundAllocation::forPayment($payment, 200),
        );
        self::assertFalse($root->hasRecordedEvents());
        $this->expectException(DomainException::class);
        $root->post($refund, EntryType::Refund, new Money(200, "USD"), $s->access->actor, staffRecorded: true);
    }
    public function testOneOutflowCanBeAllocatedAcrossTwoPaymentsAndItsReversalRestoresBoth(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment, 1000);
        $second = $this->post($s, $id, EntryType::Payment, 500);
        $allocation = new RefundAllocation([
            ["payment_operation_id" => $second->value, "amount_minor" => 400],
            ["payment_operation_id" => $first->value, "amount_minor" => 600],
        ]);
        $service = $this->service($s);
        $before = $s->sql->rows("sis_history_events");
        $review = $service->reviewRefund(
            $id,
            $first,
            new Money(1000, "USD"),
            3,
            "Fictional combined refund.",
            allocation: $allocation,
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($allocation->values(), $review["refund_allocations"]);
        $accepted = $service->commitRefund($id, $first, $review["review_token"]);
        self::assertCount(count($before) + 1, $s->sql->rows("sis_history_events"));
        $root = $s->accounts->get($id);
        $refund = new EntityId($accepted["operation_id"]);
        self::assertSame(-500, $root->balance()->minor);
        self::assertSame($allocation->values(), $root->entry($refund)["refund_allocations"]);
        self::assertSame(600, $root->paymentRefundSummary($first)["linked_refund_minor"]);
        self::assertSame(400, $root->paymentRefundSummary($second)["linked_refund_minor"]);
        self::assertSame(1000, (int) $s->sql->rows("sis_account_balances")[0]["refunds_minor"]);
        $reversal = $service->reviewReversal($id, $refund, 4, "Fictional outflow was not issued.");
        $service->commitReversal($id, $refund, $reversal["review_token"]);
        self::assertSame(1000, $s->accounts->get($id)->paymentRefundSummary($first)["remaining_refundable_minor"]);
        self::assertSame(500, $s->accounts->get($id)->paymentRefundSummary($second)["remaining_refundable_minor"]);
        self::assertSame($accepted, $service->commitRefund($id, $first, $review["review_token"]));
    }

    public function testAllocationCorrectionChangesNoMoneyAndRetainsBothRecordedDecisions(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment);
        $second = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $first, 600);
        $service = $this->service($s);
        $allocation = new RefundAllocation([
            ["payment_operation_id" => $first->value, "amount_minor" => 200],
            ["payment_operation_id" => $second->value, "amount_minor" => 400],
        ]);
        $before = $s->sql->rows("sis_account_balances")[0];
        $events = $s->sql->rows("sis_history_events");
        $review = $service->reviewCorrection(
            $id,
            $refund,
            new Money(600, "USD"),
            4,
            "Fictional allocation correction.",
            $allocation,
        );
        self::assertSame(-1400, $review["resulting_balance_minor"]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $accepted = $service->commitCorrection($id, $refund, $review["review_token"]);
        $root = $s->accounts->get($id);
        self::assertTrue($root->entry($refund)["reversed"]);
        self::assertSame(600, $root->entry($refund)["refund_allocations"][0]["amount_minor"]);
        self::assertSame(
            $allocation->values(),
            $root->entry(new EntityId($accepted["operation_id"]))["refund_allocations"],
        );
        self::assertSame(200, $root->paymentRefundSummary($first)["linked_refund_minor"]);
        self::assertSame(400, $root->paymentRefundSummary($second)["linked_refund_minor"]);
        foreach (["balance_minor", "charges_minor", "payments_minor", "refunds_minor"] as $key) {
            self::assertSame($before[$key], $s->sql->rows("sis_account_balances")[0][$key]);
        }
        self::assertSame($events, array_slice($s->sql->rows("sis_history_events"), 0, count($events)));
        self::assertSame($accepted, $service->commitCorrection($id, $refund, $review["review_token"]));
    }

    public function testEachAllocationIsCappedAndTheFullRefundMustBeAllocated(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment, 1000);
        $second = $this->post($s, $id, EntryType::Payment, 500);
        $this->refund($s, $id, $second, 400);
        $invalidPlans = [
            [["payment_operation_id" => $first->value, "amount_minor" => 500]],
            [
                ["payment_operation_id" => $first->value, "amount_minor" => 499],
                ["payment_operation_id" => $second->value, "amount_minor" => 101],
            ],
            [["payment_operation_id" => EntityId::generate()->value, "amount_minor" => 600]],
        ];
        foreach ($invalidPlans as $values) {
            $events = $s->sql->rows("sis_history_events");
            try {
                $this->service($s)->reviewPosting(
                    $id,
                    EntryType::Refund,
                    new Money(600, "USD"),
                    4,
                    "Fictional invalid allocation.",
                    allocation: new RefundAllocation($values),
                );
                self::fail("An incomplete, excessive or unrelated allocation was accepted.");
            } catch (ValidationException) {
                self::assertSame($events, $s->sql->rows("sis_history_events"));
            }
        }
    }

    public function testPaymentCorrectionFamilyCannotBeCountedTwiceInOneAllocation(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $first, 200);
        $service = $this->service($s);
        $review = $service->reviewCorrection(
            $id,
            $first,
            new Money(1500, "USD"),
            3,
            "Fictional receipt correction.",
        );
        $second = $service->commitCorrection($id, $first, $review["review_token"])["operation_id"];
        $this->expectException(ValidationException::class);
        $service->reviewCorrection(
            $id,
            $refund,
            new Money(200, "USD"),
            4,
            "Fictional duplicate family.",
            new RefundAllocation([
                ["payment_operation_id" => $first->value, "amount_minor" => 100],
                ["payment_operation_id" => $second, "amount_minor" => 100],
            ]),
        );
    }

    public function testPreviouslyUnallocatedRefundCanBeAllocatedWithAnExplicitCorrection(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $payment = $this->post($s, $id, EntryType::Payment);
        $refund = $this->post($s, $id, EntryType::Refund, 200);
        $service = $this->service($s);
        $review = $service->reviewCorrection(
            $id,
            $refund,
            new Money(200, "USD"),
            3,
            "Fictional source now verified.",
            RefundAllocation::forPayment($payment, 200),
        );
        $result = $service->commitCorrection($id, $refund, $review["review_token"]);
        $root = $s->accounts->get($id);
        self::assertArrayNotHasKey("refund_allocations", $root->entry($refund));
        self::assertSame(200, $root->paymentRefundSummary($payment)["linked_refund_minor"]);
        self::assertSame(-800, $root->balance()->minor);
        self::assertSame(0, $root->unlinkedRefundMinor());
        self::assertSame(200, $root->entry(new EntityId($result["operation_id"]))["minor"]);
    }

    public function testRefundCanMoveOffAReversedPaymentWithoutAnotherOutflow(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment);
        $second = $this->post($s, $id, EntryType::Payment);
        $refund = $this->refund($s, $id, $first, 800);
        $s->finance()->reverse($id, $first, 4, "Fictional payment not received.");
        $service = $this->service($s);
        $review = $service->reviewCorrection(
            $id,
            $refund,
            new Money(800, "USD"),
            5,
            "Fictional funding correction.",
            new RefundAllocation([
                ["payment_operation_id" => $first->value, "amount_minor" => 300],
                ["payment_operation_id" => $second->value, "amount_minor" => 500],
            ]),
        );
        $replacement = new EntityId(
            $service->commitCorrection($id, $refund, $review["review_token"])["operation_id"],
        );
        self::assertSame(-200, $s->accounts->get($id)->balance()->minor);
        self::assertSame(300, $s->accounts->get($id)->paymentRefundSummary($first)["linked_refund_minor"]);
        self::assertSame(500, $s->accounts->get($id)->paymentRefundSummary($second)["linked_refund_minor"]);
        $this->expectException(ValidationException::class);
        $service->reviewCorrection(
            $id,
            $replacement,
            new Money(800, "USD"),
            6,
            "Fictional excessive reallocation.",
            RefundAllocation::forPayment($first, 800),
        );
    }

    public function testMultiPaymentAmountCorrectionRequiresAnExplicitCompleteAllocation(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $first = $this->post($s, $id, EntryType::Payment);
        $second = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewPosting(
            $id,
            EntryType::Refund,
            new Money(600, "USD"),
            3,
            "Fictional issued refund.",
            allocation: new RefundAllocation([
                ["payment_operation_id" => $first->value, "amount_minor" => 300],
                ["payment_operation_id" => $second->value, "amount_minor" => 300],
            ]),
        );
        $refund = new EntityId($service->commitPosting($id, $review["review_token"])["operation_id"]);
        $this->expectException(ValidationException::class);
        $service->reviewCorrection($id, $refund, new Money(700, "USD"), 4, "Fictional new amount without split.");
    }

    public function testAllocationValueRejectsDuplicatesExtraFieldsNonIntegerAndOversizedLists(): void
    {
        $payment = EntityId::generate()->value;
        $entry = ["payment_operation_id" => $payment, "amount_minor" => 100];
        $invalidPlans = [
            [],
            [$entry, $entry],
            [$entry + ["extra" => true]],
            [["payment_operation_id" => $payment, "amount_minor" => "100"]],
            [["payment_operation_id" => $payment, "amount_minor" => 0]],
            array_map(
                static fn(int $n): array => [
                    "payment_operation_id" => EntityId::generate()->value,
                    "amount_minor" => $n,
                ],
                range(1, 11),
            ),
        ];
        foreach ($invalidPlans as $values) {
            try {
                new RefundAllocation($values);
                self::fail("An invalid allocation was accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
