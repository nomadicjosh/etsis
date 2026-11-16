<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\PaymentDetails;
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

final class AccountEntryReversalTest extends TestCase
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
        $operation = EntityId::generate();
        $s->finance()->post(
            $id,
            $operation,
            $type,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional posted entry.",
        );
        return $operation;
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_account_balances",
            "sis_audit",
        ]);
    }

    public function testReviewAndConfirmationShareExactReversalCalculationForAllEntryTypes(): void
    {
        foreach ([EntryType::Charge, EntryType::Payment, EntryType::Refund] as $type) {
            $s = new SisFixture();
            $id = $this->account($s);
            $this->post($s, $id, EntryType::Payment, 10000);
            $source = $this->post($s, $id, $type);
            $before = $s->accounts->get($id);
            $events = $s->sql->rows("sis_history_events");
            $service = $this->service($s);
            $review = $service->reviewReversal(
                $id,
                $source,
                3,
                "Fictional reversal <script>literal reason</script>.",
            );
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame($before->balance()->minor, $review["balance_minor"]);
            self::assertSame(-10000, $review["resulting_balance_minor"]);
            self::assertSame($type->value, $review["type"]);
            self::assertSame(1000, $review["amount_minor"]);
            $result = $service->commitReversal($id, $source, $review["review_token"]);
            self::assertSame(
                [
                    "status" => "reversed",
                    "operation_id" => $source->value,
                    "balance_minor" => -10000,
                    "position" => 4,
                ],
                $result,
            );
            $root = $s->accounts->get($id);
            self::assertTrue($root->entry($source)["reversed"]);
            self::assertSame(1000, $root->entry($source)["minor"]);
            self::assertSame(-10000, $root->balance()->minor);
            $projection = $s->sql->rows("sis_account_balances")[0];
            self::assertSame(10000, (int) $projection["payments_minor"]);
            self::assertSame(0, (int) $projection["charges_minor"]);
            self::assertSame(0, (int) $projection["refunds_minor"]);
            $history = EventStoreFixture::history($s->connection->queryBuilder())->page(
                new StreamReference($id, "student_account"),
            )->events;
            $last = $history[array_key_last($history)];
            self::assertSame(
                hash("sha256", $review["review_token"]),
                $last->event->metadata()["review_hash"],
            );
            self::assertSame("Fictional reversal <script>literal reason</script>.", $last->context->reason);
            self::assertSame($source->value, $last->context->correlation->value);
            self::assertStringNotContainsString(
                $review["review_token"],
                json_encode($s->sql->rows("sis_history_events")),
            );
        }
    }

    public function testAcceptedReversalRetryKeepsOriginalResultAfterLaterActivityAndExpiry(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $source, 2, "Fictional reviewed reversal.");
        $result = $service->commitReversal($id, $source, $review["review_token"]);
        $this->post($s, $id, EntryType::Charge, 500);
        $s->clock->time = $s->clock->now()->modify("+1 day");
        $facts = $this->facts($s);
        self::assertSame($result, $service->commitReversal($id, $source, $review["review_token"]));
        self::assertSame($facts, $this->facts($s));
        self::assertSame(500, $s->accounts->get($id)->balance()->minor);
        self::assertSame(
            ["status" => "already_recorded"],
            $s->finance()->reverse($id, $source, 1, "Fictional raw retry."),
        );
        self::assertSame($facts, $this->facts($s));
    }

    public function testStaleReviewAndInterveningRawReversalCannotAdoptReviewedProvenance(): void
    {
        foreach (["posting", "raw_reversal"] as $change) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Payment);
            $service = $this->service($s);
            $review = $service->reviewReversal($id, $source, 2, "Fictional reviewed reversal.");
            if ($change === "posting") {
                $this->post($s, $id, EntryType::Charge);
            } else {
                $s->finance()->reverse($id, $source, 2, "Fictional original raw reversal.");
            }
            $facts = $this->facts($s);
            try {
                $service->commitReversal($id, $source, $review["review_token"]);
                self::fail("Stale review accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame($facts, $this->facts($s));
            }
            self::assertNull(
                $s->accounts
                    ->get($id)
                    ->reversalResult($source, $s->access->actor, hash("sha256", $review["review_token"])),
            );
        }
    }

    public function testAnotherConfirmationCannotReplaceTheFirstReversalReason(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $first = $service->reviewReversal($id, $source, 2, "Fictional first reason.");
        $second = $service->reviewReversal($id, $source, 2, "Fictional second reason.");
        $service->commitReversal($id, $source, $first["review_token"]);
        $facts = $this->facts($s);
        try {
            $service->commitReversal($id, $source, $second["review_token"]);
            self::fail("Second reversal adopted the first.");
        } catch (DomainException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testMissingOrReversedEntriesAndOverflowReturnSafeValidationWithoutWrites(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment, Money::MAX_MINOR);
        $this->post($s, $id, EntryType::Charge, Money::MAX_MINOR);
        $this->post($s, $id, EntryType::Charge, 1);
        $service = $this->service($s);
        $facts = $this->facts($s);
        foreach ([$source, EntityId::generate()] as $invalid) {
            try {
                $service->reviewReversal($id, $invalid, 4, "Fictional invalid reversal.");
                self::fail("Invalid reversal accepted.");
            } catch (ValidationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testReceiptDetailsAreReadOnlyInReversalReviewAndRemainInHistory(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = EntityId::generate();
        $details = new PaymentDetails(PaymentMethod::Scholarship, "FICT-PRIVATE-RECEIPT");
        $s->finance()->post(
            $id,
            $source,
            EntryType::Payment,
            new Money(1000, "USD"),
            1,
            "Fictional source.",
            $details,
        );
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $source, 2, "Fictional reference retained.");
        self::assertSame($details->values(), $review["payment_details"]);
        $service->commitReversal($id, $source, $review["review_token"]);
        self::assertSame($details->values(), $s->accounts->get($id)->paymentDetails($source)->values());
        self::assertStringNotContainsString("FICT-PRIVATE-RECEIPT", json_encode($s->sql->rows("sis_audit")));
    }

    public function testWrongPurposeActorAccountEntryAndAlteredProofNeverWrite(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $source, 2, "Fictional reversal.");
        $correction = $service->reviewCorrection(
            $id,
            $source,
            new Money(1250, "USD"),
            2,
            "Fictional correction.",
        );
        $facts = $this->facts($s);
        $invalidProofs = [
            [$id, $source, $correction["review_token"]],
            [$id, $source, $review["review_token"] . "altered"],
        ];
        foreach ($invalidProofs as [$a, $op, $token]) {
            try {
                $service->commitReversal($a, $op, $token);
                self::fail("Invalid proof accepted.");
            } catch (ValidationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        foreach ([[new AccountId(), $source], [$id, EntityId::generate()]] as [$a, $op]) {
            try {
                $service->commitReversal($a, $op, $review["review_token"]);
                self::fail("Wrong scope accepted.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $s->access->actor = EntityId::generate();
        try {
            $service->commitReversal($id, $source, $review["review_token"]);
            self::fail("Wrong actor accepted.");
        } catch (AccessDeniedException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testMissingOrLostGrantsAndAuditFailuresRollBackEverything(): void
    {
        foreach (["read_only", "post_only", "late_authority", "audit_failure"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Payment);
            $service = $this->service($s);
            $review = $service->reviewReversal($id, $source, 2, "Fictional reversal.");
            $audit = $this->createMock(AuditTrail::class);
            if (in_array($failure, ["late_authority", "audit_failure"], true)) {
                $audit
                    ->expects(self::once())
                    ->method("record")
                    ->willReturnCallback(static function () use ($s, $failure): void {
                        if ($failure === "audit_failure") {
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
                $this->service($s, $audit)->commitReversal($id, $source, $review["review_token"]);
                self::fail("Denied or unaudited reversal accepted.");
            } catch (AccessDeniedException | RuntimeException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testUnacceptedReversalExpiresAndRejectedReviewKeepsOriginalFacts(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewReversal($id, $source, 2, str_repeat("界", 2000));
        $s->clock->time = $s->clock->now()->modify("+20 minutes");
        $facts = $this->facts($s);
        try {
            $service->commitReversal($id, $source, $review["review_token"]);
            self::fail("Expired reversal accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $s->finance()->reverse($id, $source, 2, "Fictional original reversal.");
        $facts = $this->facts($s);
        try {
            $service->reviewReversal($id, $source, 3, "Fictional second reversal.");
            self::fail("Already reversed entry accepted.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }
}
