<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\Service\RegistrationBatchService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationReviewTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(fee: 1000);
        $db = $f->connection->queryBuilder();
        $rules = new TermBillingService(
            new EventSourcedBillingRuleRepository(
                $f->sql,
                EventStoreFixture::framework($db),
                $f->transactions,
                EventStoreFixture::financialWriter($db),
            ),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->financialCommands(),
        );
        return [$f, $student, $section, $rules, new RegistrationBatchSelection($section->id, $student->id)];
    }

    private function fee(TermBillingService $rules, SisFixture $f, int $minor, BillingBasis $basis): void
    {
        $rules->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional " . $basis->value,
            "tuition",
            new Money($minor, "USD"),
            "Fictional approved fee.",
            $basis,
        );
    }

    private function snapshot(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_registration_batches",
        ]);
    }

    private function commit(
        RegistrationBatchService $service,
        RegistrationBatchSelection $selection,
        array $review,
        bool $staff = false,
    ): array {
        return $service->commit(
            new EntityId($review["operation_id"]),
            $selection,
            $review["revision"],
            $review["review_token"],
            $staff,
        );
    }

    public function testStudentAndStaffSeeExactChargesWithoutAccountBalancesAndPostThatSamePrice(): void
    {
        [$f, $student, $section, $rules, $selection] = $this->fixture();
        $this->fee($rules, $f, 500, BillingBasis::Term);
        $this->fee($rules, $f, 125, BillingBasis::RegisteredCredit);
        $before = $this->snapshot($f);
        $service = $f->registrationBatches();
        $review = $service->review($selection, false);
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(1875, $review["students"][0]["charges"]["total_minor"]);
        self::assertEqualsCanonicalizing(
            [1000, 500, 375],
            array_column($review["students"][0]["charges"]["items"], "minor"),
        );
        $json = json_encode($review, JSON_THROW_ON_ERROR);
        $privateFields = [
            "account_id",
            "balance_before",
            "balance_after",
            "account_position",
            "account_balance",
        ];
        foreach ($privateFields as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $staffReview = $service->review($selection);
        self::assertSame($review["students"][0]["charges"], $staffReview["students"][0]["charges"]);
        $f->access->staff = false;
        $result = $this->commit($service, $selection, $review);
        self::assertSame("N", $result["registrations"][0]["status"]);
        $accountId = new AccountId(
            $f->sql->one("sis_student_accounts", ["student_id" => $student->id->value])["id"],
        );
        $account = $f->accounts->get($accountId);
        self::assertSame(1875, $account->balance()->minor);
        self::assertCount(3, $account->entries());
        $existing = $service->review($selection, false);
        self::assertSame(0, $existing["students"][0]["charges"]["total_minor"]);
        self::assertSame([], $existing["students"][0]["charges"]["items"]);
        $next = $f->section(fee: 1000);
        $nextReview = $service->review(new RegistrationBatchSelection($next->id, $student->id), false);
        self::assertSame(1375, $nextReview["students"][0]["charges"]["total_minor"]);
        self::assertContains(
            "not_due",
            array_column($nextReview["students"][0]["charges"]["items"], "status"),
        );
    }

    public static function changes(): array
    {
        return [["rule"], ["section_fee"], ["credits"], ["account"]];
    }

    #[DataProvider("changes")]
    public function testChangedBillingOrSectionInputsRequireAnotherReview(string $change): void
    {
        [$f, $student, $section, $rules, $selection] = $this->fixture();
        $service = $f->registrationBatches();
        $review = $service->review($selection);
        match ($change) {
            "rule" => $this->fee($rules, $f, 200, BillingBasis::Term),
            "section_fee" => $f->sql->update(
                "sis_sections",
                ["fee_minor" => 2000],
                ["id" => $section->id->value],
            ),
            "credits" => $f->sql->update(
                "sis_courses",
                ["credits_milli" => 4000],
                ["id" => $section->courseId->value],
            ),
            "account" => $f->finance()->open($student->id, $f->term, "USD"),
        };
        $before = $this->snapshot($f);
        try {
            $this->commit($service, $selection, $review, true);
            self::fail("Changed registration inputs bypassed the reviewed price.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testExpiredPendingReviewIsRejectedButAcceptedRetryAfterDropReturnsOriginalResult(): void
    {
        [$f, $student, $section, , $selection] = $this->fixture();
        $service = $f->registrationBatches();
        $expired = $service->review($selection, false);
        $f->clock->time = $f->clock->time->modify("+20 minutes");
        $before = $this->snapshot($f);
        try {
            $this->commit($service, $selection, $expired);
            self::fail("An expired review created registrations or charges.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $review = $service->review($selection, false);
        $result = $this->commit($service, $selection, $review);
        $f->service->drop($student->id, $section->id);
        $f->clock->time = $f->clock->time->modify("+2 days");
        $after = $this->snapshot($f);
        self::assertSame($result, $this->commit($service, $selection, $review));
        self::assertSame($after, $this->snapshot($f));
        self::assertNull($f->registration->enrollment($student->id, $section->id));
    }

    public function testSelfServiceProofCannotBecomeStaffOverrideOrSelectAnotherStudent(): void
    {
        [$f, , , , $selection] = $this->fixture();
        $service = $f->registrationBatches();
        $review = $service->review($selection, false);
        try {
            $this->commit($service, $selection, $review, true);
            self::fail("A student review became a staff override.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $this->commit($service, $selection, $review);
    }

    public function testSelfServiceCannotCreateAGroupOrUseStaffDeadlineOverrides(): void
    {
        [$f, $student, $section, , $selection] = $this->fixture();
        $other = $f->student($f->user());
        $service = $f->registrationBatches();
        try {
            $service->review(new RegistrationBatchSelection($section->id, $student->id, $other->id), false);
            self::fail("Student self-service created a group review.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_registration_batches"));
        }
        $f->clock->time = $f->clock->time->modify("+2 years");
        $review = $service->review($selection, false);
        self::assertContains("Self-service registration is closed.", $review["students"][0]["issues"]);
        $this->expectException(ValidationException::class);
        $this->commit($service, $selection, $review);
    }

    public function testCurrencyProblemsAreExplainedAndNoGroupIsPartlyRegisteredOrCharged(): void
    {
        [$f, $student, $section, ,] = $this->fixture();
        $other = $f->student();
        $f->finance()->open($other->id, $f->term, "EUR");
        $service = $f->registrationBatches();
        $selection = new RegistrationBatchSelection($section->id, $student->id, $other->id);
        $review = $service->review($selection);
        $charges = array_column($review["students"], "charges", "id");
        self::assertNull($charges[$other->id->value]["total_minor"]);
        self::assertNotEmpty($charges[$other->id->value]["problems"]);
        $before = $this->snapshot($f);
        try {
            $this->commit($service, $selection, $review, true);
            self::fail("A currency problem left a partially registered group.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public static function failures(): array
    {
        return [["audit"], ["expiry"], ["authority"]];
    }

    #[DataProvider("failures")]
    public function testFinalAuditExpiryAndAuthorityChecksRollBackAllDomainsAndReceipt(string $failure): void
    {
        [$f, , , , $selection] = $this->fixture();
        $before = $this->snapshot($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(function () use ($f, $failure): void {
            if ($failure === "audit") {
                throw new \RuntimeException("Fictional registration audit failure.");
            }
            if ($failure === "expiry") {
                $f->clock->time = $f->clock->time->modify("+20 minutes");
            } else {
                $f->access->staff = false;
            }
        });
        $service = $f->registrationBatches(audit: $audit);
        $review = $service->review($selection);
        try {
            $this->commit($service, $selection, $review, true);
            self::fail("A final failure left a partially accepted registration.");
        } catch (\RuntimeException $error) {
            self::assertSame(
                match ($failure) {
                    "audit" => \RuntimeException::class,
                    "expiry" => ConcurrentModificationException::class,
                    "authority" => AccessDeniedException::class,
                },
                $error::class,
            );
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testZeroCreditPricesDoNotInventFinancialEntries(): void
    {
        [$f, , $section, $rules, $selection] = $this->fixture();
        $this->fee($rules, $f, 1000, BillingBasis::RegisteredCredit);
        $f->sql->update("sis_sections", ["fee_minor" => 0], ["id" => $section->id->value]);
        $f->sql->update("sis_courses", ["credits_milli" => 0], ["id" => $section->courseId->value]);
        $service = $f->registrationBatches();
        $review = $service->review($selection);
        self::assertSame(0, $review["students"][0]["charges"]["total_minor"]);
        $result = $this->commit($service, $selection, $review, true);
        self::assertSame(1, $result["created"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }

    public function testRegistrarCanReviewNewChargesWithoutFinanceOrDirectoryReadAccess(): void
    {
        [$f, $student, , , $selection] = $this->fixture();
        $account = $f->finance()->open($student->id, $f->term, "USD");
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $review = $f->registrationBatches()->review($selection);
        self::assertSame(1000, $review["students"][0]["charges"]["total_minor"]);
        self::assertStringNotContainsString(
            (string) $account->aggregateId(),
            json_encode($review, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            "balance",
            json_encode($review["students"][0]["charges"], JSON_THROW_ON_ERROR),
        );
        $quote = $f->registrationBilling->registrationQuote(
            $student->id,
            $f->term,
            $f->registration->section($selection->section)->fee,
            3000,
            new \Domain\Shared\EventSourcing\HistoryReadBudget(),
        );
        self::assertSame(
            $quote->summary(),
            json_decode(json_encode($quote, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAQuoteForAnotherStudentCannotBeAppliedToThisRegistration(): void
    {
        [$f, $student, $section] = $this->fixture();
        $other = $f->student();
        $quote = $f->registrationBilling->registrationQuote(
            $other->id,
            $f->term,
            $section->fee,
            3000,
            new \Domain\Shared\EventSourcing\HistoryReadBudget(),
        );
        $before = $this->snapshot($f);
        try {
            $f->service->register($student->id, $section->id, true, chargeQuote: $quote);
            self::fail("A different student pricing view was applied.");
        } catch (\LogicException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public static function excessivePrices(): array
    {
        return [[BillingBasis::Term], [BillingBasis::RegisteredCredit]];
    }

    #[DataProvider("excessivePrices")]
    public function testUnsupportedCombinedOrCreditPricesBlockWithoutPartialCharges(BillingBasis $basis): void
    {
        [$f, , , $rules, $selection] = $this->fixture();
        $this->fee($rules, $f, Money::MAX_MINOR, $basis);
        $service = $f->registrationBatches();
        $review = $service->review($selection);
        self::assertNull($review["students"][0]["charges"]["total_minor"]);
        self::assertNotEmpty($review["students"][0]["charges"]["problems"]);
        $before = $this->snapshot($f);
        try {
            $this->commit($service, $selection, $review, true);
            self::fail("An unsupported amount left partial charges or academic records.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
}
