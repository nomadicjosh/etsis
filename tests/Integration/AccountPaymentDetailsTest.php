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
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class AccountPaymentDetailsTest extends TestCase
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

    private function post(
        SisFixture $s,
        AccountId $id,
        EntryType $type,
        ?PaymentDetails $details = null,
    ): EntityId {
        $service = $this->service($s);
        $review = $service->reviewPosting(
            $id,
            $type,
            new Money(1000, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional receipt recorded.",
            $details,
        );
        return new EntityId($service->commitPosting($id, $review["review_token"])["operation_id"]);
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

    public function testEveryMethodIsOptionalAndPreservedPrivatelyWithoutChangingAccountEntryShape(): void
    {
        foreach (PaymentMethod::cases() as $method) {
            $s = new SisFixture();
            $id = $this->account($s);
            $details = new PaymentDetails($method, "FICTIONAL-支払-1");
            $op = $this->post($s, $id, EntryType::Payment, $details);
            $root = $s->accounts->get($id);
            self::assertSame($details->values(), $root->paymentDetails($op)->values());
            self::assertSame(-1000, $root->balance()->minor);
            self::assertArrayNotHasKey("payment_details", $root->entry($op));
            self::assertStringNotContainsString(
                "FICTIONAL-支払-1",
                json_encode($s->sql->rows("sis_history_events")),
            );
            self::assertStringNotContainsString("FICTIONAL-支払-1", json_encode($s->sql->rows("sis_audit")));
        }
        $s = new SisFixture();
        $id = $this->account($s);
        $unknown = $this->post($s, $id, EntryType::Payment);
        self::assertNull($this->service($s)->receipt($id, $unknown)["payment_details"]);
        $referenceOnly = $this->post($s, $id, EntryType::Payment, new PaymentDetails(reference: "FICT-2"));
        self::assertSame(
            ["method" => null, "reference" => "FICT-2"],
            $this->service($s)->receipt($id, $referenceOnly)["payment_details"],
        );
    }

    public function testRefundsSupportReceiptDetailsAndChargesRejectThemBeforeWriting(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $this->post($s, $id, EntryType::Payment);
        $refund = $this->post($s, $id, EntryType::Refund, new PaymentDetails(PaymentMethod::Check, "FICT-R"));
        self::assertSame("check", $s->accounts->get($id)->paymentDetails($refund)->method->value);
        $facts = $this->facts($s);
        try {
            $this->service($s)->reviewPosting(
                $id,
                EntryType::Charge,
                new Money(1, "USD"),
                $s->accounts->get($id)->playhead(),
                "Fictional invalid charge.",
                new PaymentDetails(PaymentMethod::Cash),
            );
            self::fail("A charge cannot carry payment details.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testReceiptCorrectionsRetainBeforeAndAfterAndNeverMoveMoney(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment, new PaymentDetails(PaymentMethod::Check, "FICT-OLD"));
        $service = $this->service($s);
        $projection = $s->sql->rows("sis_account_balances")[0];
        $review = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Grant, "FICT-NEW"),
            2,
            "Fictional corrected receipt.",
        );
        self::assertSame(-1000, $review["resulting_balance_minor"]);
        self::assertSame("FICT-OLD", $review["previous_payment_details"]["reference"]);
        $result = $service->commitPaymentDetails($id, $op, $review["review_token"]);
        self::assertSame("receipt_corrected", $result["status"]);
        self::assertSame(-1000, $result["balance_minor"]);
        $projection["position"] = 3;
        self::assertEquals($projection, $s->sql->rows("sis_account_balances")[0]);
        self::assertCount(1, $s->accounts->get($id)->entries());
        $history = new AccountEntryHistory(
            EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            new class implements ActorDirectory {
                public function names(array $ids): array
                {
                    return [];
                }
            },
            $s->audit,
        );
        $page = $history->page($id, $op);
        self::assertSame(["receipt_corrected", "posted"], array_column($page["items"], "action"));
        self::assertSame("FICT-OLD", $page["items"][0]["previous_payment_details"]["reference"]);
        self::assertSame("FICT-NEW", $page["items"][0]["payment_details"]["reference"]);
        self::assertSame("FICT-OLD", $page["items"][1]["payment_details"]["reference"]);
        self::assertSame($op->value, $page["items"][0]["operation_id"]);
        $captured = $history->page($id, $op, throughPosition: 2);
        self::assertCount(1, $captured["items"]);
        self::assertSame("FICT-OLD", $captured["items"][0]["payment_details"]["reference"]);
    }

    public function testAmountReplacementsShareReceiptButEarlierSnapshotsStayAsRecorded(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment, new PaymentDetails(PaymentMethod::Loan, "FICT-1"));
        $service = $this->service($s);
        $amountReview = $service->reviewCorrection(
            $id,
            $op,
            new Money(1250, "USD"),
            2,
            "Fictional amount correction.",
        );
        self::assertSame("FICT-1", $amountReview["payment_details"]["reference"]);
        $replacement = new EntityId(
            $service->commitCorrection($id, $op, $amountReview["review_token"])["operation_id"],
        );
        $receiptReview = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Scholarship, "FICT-2"),
            3,
            "Fictional scholarship receipt.",
        );
        $accepted = $service->commitPaymentDetails($id, $op, $receiptReview["review_token"]);
        self::assertSame("FICT-2", $s->accounts->get($id)->paymentDetails($replacement)->reference);
        $clear = $service->reviewPaymentDetails(
            $id,
            $replacement,
            new PaymentDetails(),
            4,
            "Fictional reference cleared.",
        );
        $service->commitPaymentDetails($id, $replacement, $clear["review_token"]);
        self::assertSame(
            ["method" => null, "reference" => null],
            $service->receipt($id, $op)["payment_details"],
        );
        $s->clock->time = $s->clock->now()->modify("+1 day");
        $facts = $this->facts($s);
        self::assertSame($accepted, $service->commitPaymentDetails($id, $op, $receiptReview["review_token"]));
        self::assertSame($facts, $this->facts($s));
        self::assertSame(-1250, $s->accounts->get($id)->balance()->minor);
        $history = new AccountEntryHistory(
            EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            new class implements ActorDirectory {
                public function names(array $ids): array
                {
                    return [];
                }
            },
            $s->audit,
        );
        $page = $history->page($id, $replacement);
        self::assertSame(
            ["receipt_corrected", "receipt_corrected", "corrected", "posted"],
            array_column($page["items"], "action"),
        );
        self::assertSame("FICT-1", $page["items"][2]["payment_details"]["reference"]);
        self::assertSame("FICT-1", $page["items"][3]["payment_details"]["reference"]);
    }

    public function testRawPostingRetryComparesOpeningDetailsAfterReceiptWasCorrected(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = EntityId::generate();
        $details = new PaymentDetails(PaymentMethod::Cash, "FICT-1");
        $s->finance()->post(
            $id,
            $op,
            EntryType::Payment,
            new Money(1000, "USD"),
            1,
            "Fictional opening.",
            $details,
        );
        $service = $this->service($s);
        $review = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Check, "FICT-2"),
            2,
            "Fictional correction.",
        );
        $service->commitPaymentDetails($id, $op, $review["review_token"]);
        $facts = $this->facts($s);
        self::assertSame(
            "already_recorded",
            $s
                ->finance()
                ->post($id, $op, EntryType::Payment, new Money(1000, "USD"), 1, "Fictional retry.", $details)[
                "status"
            ],
        );
        self::assertSame($facts, $this->facts($s));
        $this->expectException(DomainException::class);
        $s->finance()->post(
            $id,
            $op,
            EntryType::Payment,
            new Money(1000, "USD"),
            1,
            "Fictional changed retry.",
            new PaymentDetails(PaymentMethod::Check, "FICT-2"),
        );
    }

    public function testUnknownHistoricalDetailsStayUnknownAndLaterObservationIsExplicit(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Other),
            2,
            "Fictional later observation.",
        );
        self::assertNull($review["previous_payment_details"]);
        $service->commitPaymentDetails($id, $op, $review["review_token"]);
        self::assertSame("other", $service->receipt($id, $op)["payment_details"]["method"]);
    }

    public function testReceiptReviewRejectsNoChangeAndNonStaffSources(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $facts = $this->facts($s);
        try {
            $this->service($s)->reviewPaymentDetails(
                $id,
                $op,
                new PaymentDetails(),
                2,
                "Fictional no change.",
            );
            self::fail("A no-op must not produce a review.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $automatic = EntityId::generate();
        $root = $s->accounts->get($id);
        $root->post($automatic, EntryType::Payment, new Money(1, "USD"), $s->access->actor);
        $s->saveAccount($root);
        self::assertFalse($s->accounts->get($id)->canCorrectPaymentDetails($automatic));
        $this->expectException(ValidationException::class);
        $this->service($s)->reviewPaymentDetails(
            $id,
            $automatic,
            new PaymentDetails(PaymentMethod::Cash),
            3,
            "Fictional unsupported source.",
        );
    }

    public function testReceiptsRequireBothGrantsAndCommitRejectsStaleHeadAndWrongIntent(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Cash),
            2,
            "Fictional receipt.",
        );
        foreach (["sis:finance:read", "sis:finance:post"] as $grant) {
            $s->access->staff = false;
            $s->access->grants = [$grant];
            $facts = $this->facts($s);
            try {
                $service->receipt($id, $op);
                self::fail("Both permissions are required.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
            try {
                $service->commitPaymentDetails($id, $op, $review["review_token"]);
                self::fail("Both permissions are required.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $s->access->staff = true;
        $s->access->grants = [];
        try {
            $service->commitCorrection($id, $op, $review["review_token"]);
            self::fail("Receipt proof cannot change an amount.");
        } catch (ValidationException) {
            self::assertSame(-1000, $s->accounts->get($id)->balance()->minor);
        }
        $this->post($s, $id, EntryType::Payment);
        $this->expectException(ConcurrentModificationException::class);
        $service->commitPaymentDetails($id, $op, $review["review_token"]);
    }

    public function testAuditFailureRollsBackReceiptEventProjectionAndReceiptTogether(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $review = $this->service($s)->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Check, "FICT-PRIVATE"),
            2,
            "Fictional correction.",
        );
        $audit = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $facts = $this->facts($s);
        try {
            $this->service($s, $audit)->commitPaymentDetails($id, $op, $review["review_token"]);
            self::fail("Audit failure must abort.");
        } catch (RuntimeException) {
            self::assertSame($facts, $this->facts($s));
        }
        self::assertNull($s->accounts->get($id)->paymentDetails($op));
    }

    public function testReceiptCommitRollsBackWhenReadAuthorityIsLostAfterSaving(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $review = $this->service($s)->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Grant),
            2,
            "Fictional reviewed grant.",
        );
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s): void {
                $s->access->staff = false;
                $s->access->grants = ["sis:finance:post"];
            });
        $facts = $this->facts($s);
        try {
            $this->service($s, $audit)->commitPaymentDetails($id, $op, $review["review_token"]);
            self::fail("Late loss of independent read authority must roll back.");
        } catch (AccessDeniedException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testExpiredAlteredWrongAccountAndWrongActorReceiptProofsWriteNothing(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $op = $this->post($s, $id, EntryType::Payment);
        $service = $this->service($s);
        $review = $service->reviewPaymentDetails(
            $id,
            $op,
            new PaymentDetails(PaymentMethod::Check, "FICT-ONLY"),
            2,
            "Fictional reviewed reference.",
        );
        $facts = $this->facts($s);
        foreach ([new AccountId(), $id] as $other) {
            try {
                $service->commitPaymentDetails(
                    $other,
                    $other === $id ? EntityId::generate() : $op,
                    $review["review_token"],
                );
                self::fail("The proof must name the same account and entry.");
            } catch (AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $actor = $s->access->actor;
        $s->access->actor = EntityId::generate();
        try {
            $service->commitPaymentDetails($id, $op, $review["review_token"]);
            self::fail("Wrong actor accepted.");
        } catch (AccessDeniedException) {
            self::assertSame($facts, $this->facts($s));
        }
        $s->access->actor = $actor;
        try {
            $service->commitPaymentDetails($id, $op, $review["review_token"] . "altered");
            self::fail("Altered proof accepted.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        $s->clock->time = $s->clock->now()->modify("+20 minutes");
        try {
            $service->commitPaymentDetails($id, $op, $review["review_token"]);
            self::fail("Expired proof accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testReferenceValidationRejectsLongInvalidUtf8AndControlText(): void
    {
        foreach ([str_repeat("界", 121), "FICT\n1", "FICT\x00", "\xB1\x31", "FICT\u{202E}1"] as $reference) {
            try {
                new PaymentDetails(reference: $reference);
                self::fail("Invalid private reference accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(
            str_repeat("界", 120),
            new PaymentDetails(reference: str_repeat("界", 120))->reference,
        );
        self::assertNull(new PaymentDetails(reference: "  ")->reference);
    }
}
