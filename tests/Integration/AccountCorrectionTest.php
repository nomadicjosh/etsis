<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Service\AccountCorrectionService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
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

final class AccountCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?AccountRepository $accounts = null,
    ): AccountCorrectionService {
        return new AccountCorrectionService(
            $accounts ?? $s->accounts,
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

    private function post(SisFixture $s, AccountId $id, EntryType $type, int $minor): EntityId
    {
        $operation = EntityId::generate();
        $s->finance()->post(
            $id,
            $operation,
            $type,
            new Money($minor, "USD"),
            $s->accounts->get($id)->playhead(),
            "Fictional staff-recorded source.",
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
            "sis_student_accounts",
            "sis_audit",
        ]);
    }

    public function testReviewedCorrectionsRetainOriginalsAndRebuildExactBalancesAndCategoryTotals(): void
    {
        foreach ([EntryType::Charge, EntryType::Payment, EntryType::Refund] as $type) {
            $s = new SisFixture();
            $id = $this->account($s);
            $this->post($s, $id, EntryType::Payment, 10000);
            $source = $this->post($s, $id, $type, 1000);
            $events = $s->sql->rows("sis_history_events");
            $service = $this->service($s);
            $before = $s->accounts->get($id);
            $review = $service->review(
                $id,
                $source,
                new Money(1250, "USD"),
                $before->playhead(),
                "Fictional private amount correction.\n<script>literal reason</script>",
            );
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame(1000, $review["original_minor"]);
            $result = $service->commit($id, $source, $review["review_token"]);
            $root = $s->accounts->get($id);
            $operation = new EntityId($result["operation_id"]);
            self::assertTrue($root->entry($source)["reversed"]);
            self::assertSame(1000, $root->entry($source)["minor"]);
            self::assertSame($source->value, $root->entry($operation)["corrects_operation_id"]);
            self::assertSame($operation->value, $root->entry($source)["corrected_by_operation_id"]);
            self::assertFalse($root->canCorrectAmount($source));
            self::assertTrue($root->canCorrectAmount($operation));
            self::assertSame($review["resulting_balance_minor"], $root->balance()->minor);
            self::assertSame($review["resulting_balance_minor"], $result["balance_minor"]);
            $projection = $s->sql->one("sis_account_balances", ["id" => (string) $id]);
            self::assertSame($root->balance()->minor, (int) $projection["balance_minor"]);
            self::assertSame($root->playhead(), (int) $projection["position"]);
            self::assertSame($type === EntryType::Payment ? 11250 : 10000, (int) $projection["payments_minor"]);
            self::assertSame($type === EntryType::Charge ? 1250 : 0, (int) $projection["charges_minor"]);
            self::assertSame($type === EntryType::Refund ? 1250 : 0, (int) $projection["refunds_minor"]);
            $stored = $s->sql->rows("sis_history_events");
            self::assertCount(count($events) + 1, $stored);
            self::assertSame($events, array_slice($stored, 0, count($events)));
            self::assertStringNotContainsString("literal reason", json_encode($stored));
            $history = EventStoreFixture::history($s->connection->queryBuilder())->page(
                new StreamReference($id, "student_account"),
            )->events;
            $last = $history[array_key_last($history)];
            self::assertSame($source->value, $last->context->causation->value);
            self::assertSame($operation->value, $last->context->correlation->value);
            self::assertSame($s->access->actor->value, $last->context->actor->value);
            self::assertSame($review["reason"], $last->context->reason);
            self::assertSame(
                $result,
                $root->correctionResult($operation, $s->access->actor, hash("sha256", $review["review_token"])),
            );
        }
    }

    public function testAcceptedRetriesKeepTheirOriginalResultAfterFurtherCorrectionsReversalsAndExpiry(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment, 1000);
        $service = $this->service($s);
        $review = $service->review($id, $source, new Money(1250, "USD"), 2, "Fictional first correction.");
        $result = $service->commit($id, $source, $review["review_token"]);
        $replacement = new EntityId($result["operation_id"]);
        $later = $service->review($id, $replacement, new Money(1400, "USD"), 3, "Fictional later correction.");
        $second = $service->commit($id, $replacement, $later["review_token"]);
        $s->finance()->reverse($id, new EntityId($second["operation_id"]), 4, "Fictional later reversal.");
        $this->post($s, $id, EntryType::Charge, 100);
        $s->clock->time = $s->clock->time->modify("+1 day");
        $facts = $this->facts($s);
        self::assertSame($result, $service->commit($id, $source, $review["review_token"]));
        self::assertSame($facts, $this->facts($s));
        self::assertSame(100, $s->accounts->get($id)->balance()->minor);
        $decoded = json_decode($s->dropCipher->open($review["review_token"]), true);
        $decoded["reason"] = "A different reviewed instruction cannot replace an accepted reason.";
        try {
            $service->commit($id, $source, $s->dropCipher->seal(json_encode($decoded)));
            self::fail("A different proof replaced an accepted correction.");
        } catch (DomainException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testAutomatedAndEarlierUnknownSourcesAreNotAdoptedAsStaffRecorded(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $root = $s->accounts->get($id);
        $unknown = EntityId::generate();
        $root->post($unknown, EntryType::Charge, new Money(100, "USD"), $s->access->actor);
        $s->saveAccount($root);
        $facts = $this->facts($s);
        self::assertFalse($s->accounts->get($id)->canCorrectAmount($unknown));
        try {
            $this->service($s)->review($id, $unknown, new Money(200, "USD"), 2, "Unknown source.");
            self::fail("An automated or earlier unknown source was adopted as manual.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
        // Ordinary operation retries must not add a provenance marker to an earlier event.
        $s->finance()->post($id, $unknown, EntryType::Charge, new Money(100, "USD"), 1, "A later retry.");
        self::assertSame($facts, $this->facts($s));
        self::assertFalse($s->accounts->get($id)->canCorrectAmount($unknown));
    }

    public function testInvalidOrOverRefundCorrectionsCannotChangeHistory(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $this->post($s, $id, EntryType::Payment, 1000);
        $source = $this->post($s, $id, EntryType::Refund, 500);
        $service = $this->service($s);
        $facts = $this->facts($s);
        $invalidAmounts = [new Money(500, "USD"), new Money(0, "USD"),
            new Money(600, "EUR"), new Money(1001, "USD")];
        foreach ($invalidAmounts as $amount) {
            try {
                $service->review($id, $source, $amount, 3, "Invalid corrected refund.");
                self::fail("An invalid correction was reviewed.");
            } catch (ValidationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
        $s->finance()->reverse($id, $source, 3, "Fictional reversal.");
        $facts = $this->facts($s);
        try {
            $service->review($id, $source, new Money(400, "USD"), 4, "Already reversed.");
            self::fail("A reversed source was corrected.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
    }

    public function testStaleExpiredTamperedAndWrongAccountReviewsCannotPostCorrections(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment, 1000);
        $service = $this->service($s);
        $review = $service->review($id, $source, new Money(1250, "USD"), 2, "Fictional stale review.");
        $this->post($s, $id, EntryType::Charge, 100);
        $other = $s->user();
        $facts = $this->facts($s);
        foreach (["stale", "expired", "tampered", "account", "source", "actor"] as $failure) {
            try {
                match ($failure) {
                    "expired" => ($s->clock->time = $s->clock->time->modify("+21 minutes")),
                    "actor" => ($s->access->actor = $other),
                    default => null,
                };
                $service->commit(
                    $failure === "account" ? new AccountId() : $id,
                    $failure === "source" ? EntityId::generate() : $source,
                    $failure === "tampered" ? "invalid-token" : $review["review_token"],
                );
                self::fail("An unconfirmed or changed instruction posted a correction.");
            } catch (ConcurrentModificationException | ValidationException | AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testReadAndPostingPermissionsAreBothRequiredForReviewsAndAcceptedRetries(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $source = $this->post($s, $id, EntryType::Payment, 1000);
        $service = $this->service($s);
        $review = $service->review($id, $source, new Money(1250, "USD"), 2, "Fictional private correction.");
        $service->commit($id, $source, $review["review_token"]);
        $s->access->staff = false;
        $facts = $this->facts($s);
        foreach ([["sis:finance:read"], ["sis:finance:post"], []] as $grants) {
            $s->access->grants = $grants;
            foreach (["review", "commit"] as $action) {
                try {
                    if ($action === "review") {
                        $service->review($id, $source, new Money(1400, "USD"), 3, "Denied review.");
                    } else {
                        $service->commit($id, $source, $review["review_token"]);
                    }
                    self::fail("Partial authority allowed a financial correction.");
                } catch (AccessDeniedException) {
                    self::assertSame($facts, $this->facts($s));
                }
            }
        }
    }

    public function testLateFailuresRollBackCorrectionReceiptProjectionAndRequiredAudit(): void
    {
        foreach (["audit", "actor", "read", "post", "expiry", "projection", "history_limit"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Payment, 1000);
            $review = $this->service($s)->review($id, $source, new Money(1250, "USD"), 2, "Fictional correction.");
            $other = $s->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action, $subject) use ($s, $failure, $other): void {
                    $s->audit->record($actor, $action, $subject);
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional correction audit failure.");
                    }
                    if ($failure === "actor") {
                        $s->access->actor = $other;
                    }
                    if (in_array($failure, ["read", "post"], true)) {
                        $s->access->staff = false;
                        $s->access->grants = $failure === "read" ? ["sis:finance:post"] : ["sis:finance:read"];
                    }
                    if ($failure === "expiry") {
                        $s->clock->time = $s->clock->time->modify("+21 minutes");
                    }
                });
            if ($failure === "projection") {
                $s->sql->update("sis_account_balances", ["payments_minor" => 999], ["id" => (string) $id]);
            }
            $repository = null;
            if ($failure === "history_limit") {
                $repository = $this->createStub(AccountRepository::class);
                $repository->method("get")->willThrowException(new HistoryReadLimitException());
            }
            $facts = $this->facts($s);
            try {
                $this->service($s, $audit, $repository)->commit($id, $source, $review["review_token"]);
                self::fail("An interrupted correction committed financial facts.");
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testReviewAuditAndAuthorityFailuresLeaveNoReviewAuditOrMonetaryChanges(): void
    {
        foreach (["audit", "permission", "actor"] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $source = $this->post($s, $id, EntryType::Payment, 1000);
            $other = $s->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action, $subject) use ($s, $failure, $other): void {
                    $s->audit->record($actor, $action, $subject);
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional review audit failure.");
                    }
                    if ($failure === "permission") {
                        $s->access->staff = false;
                        $s->access->grants = [];
                    } else {
                        $s->access->actor = $other;
                    }
                });
            $facts = $this->facts($s);
            try {
                $this->service($s, $audit)->review($id, $source, new Money(1250, "USD"), 2, "Private review.");
                self::fail("A review survived a late authority or audit failure.");
            } catch (RuntimeException | AccessDeniedException) {
                self::assertSame($facts, $this->facts($s));
            }
        }
    }

    public function testRegistrationChargesStayWithTheirOriginalDropWorkflow(): void
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
        $account = $s->accounts->get($id);
        self::assertFalse($account->canCorrectAmount($enrollment->id));
        $facts = $this->facts($s);
        try {
            $this->service($s)->review(
                $id,
                $enrollment->id,
                new Money(200, "USD"),
                $account->playhead(),
                "This registration charge has a separate source workflow.",
            );
            self::fail("A registration charge was replaced outside its source workflow.");
        } catch (ValidationException) {
            self::assertSame($facts, $this->facts($s));
        }
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
        self::assertTrue($s->accounts->get($id)->entry($enrollment->id)["reversed"]);
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
    }
}
