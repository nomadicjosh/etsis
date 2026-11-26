<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayObject;
use Domain\Finance\BillingRule;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\RegistrationPrice;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use SisFixture;

final class RegistrationPricingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261116000006_AddSisBillingPricing.php";
    }

    private function rules(SisFixture $f): TermBillingService
    {
        $db = $f->connection->queryBuilder();
        return new TermBillingService(
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
    }

    private function rate(
        SisFixture $f,
        int $minor = 12500,
        ?EntityId $program = null,
        string $currency = "USD",
    ): BillingRule {
        return $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            $program,
            "Fictional tuition",
            "tuition",
            new Money($minor, $currency),
            "Fictional approved registration rate.",
            BillingBasis::RegisteredCredit,
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

    private function drop(SisFixture $f, EntityId $student, EntityId $section): array
    {
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

    public function testEachRegistrationRetainsItsActualCreditsAndRateWithOriginalResultRetries(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $rate = $this->rate($f);
        $section = $f->section(fee: 0);
        $enrollment = $f->service->register($student->id, $section->id);
        $account = $this->account($f, $student->id);
        $root = $f->accounts->get($account);
        self::assertSame(37500, $root->balance()->minor);
        self::assertCount(1, $root->entries());
        $price = $root->entries()[0]["registration_price"];
        self::assertSame($enrollment->id->value, $price["enrollment_id"]);
        self::assertSame((string) $rate->aggregateId(), $price["rule_id"]);
        self::assertSame(3000, $price["credits_milli"]);
        self::assertSame(12500, $price["rate_minor"]);
        self::assertFalse(
            $root->canCorrectAmount(new EntityId($root->entries()[0]["operation_id"])),
        );
        $events = $f->financialEvents();
        $this->rules($f)->retire($rate->aggregateId(), 1, "Fictional rate retired.");
        $this->rate($f, 20000);
        $afterSetup = $f->financialEvents();
        self::assertSame(
            $enrollment->id->value,
            $f->service->register($student->id, $section->id)->id->value,
        );
        self::assertSame($afterSetup, $f->financialEvents());
        self::assertSame($price, $f->accounts->get($account)->entries()[0]["registration_price"]);
        self::assertSame($events, array_slice($afterSetup, 0, count($events)));
    }

    public function testEarlyDropReversesOriginalRatesSectionFeeAndLinkedCreditsButNotFlatTermFees(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $this->rate($f, 10000);
        $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional term fee",
            "fee",
            new Money(5000, "USD"),
            "Fictional term fee approved.",
        );
        $section = $f->section(fee: 1000);
        $enrollment = $f->service->register($student->id, $section->id);
        $id = $this->account($f, $student->id);
        $root = $f->accounts->get($id);
        $rateEntry = array_values(
            array_filter(
                $root->entries(),
                static fn(array $e): bool => isset($e["registration_price"]),
            ),
        )[0];
        $reviews = new \Domain\Finance\Service\AccountEntryReviewService(
            $f->accounts,
            $f->access,
            $f->transactions,
            $f->financialCommands(),
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $credit = $reviews->reviewChargeCredit(
            $id,
            new EntityId($rateEntry["operation_id"]),
            new Money(1000, "USD"),
            $root->playhead(),
            "Fictional approved tuition adjustment.",
        );
        $reviews->commitChargeCredit(
            $id,
            new EntityId($rateEntry["operation_id"]),
            $credit["review_token"],
        );
        $receipt = $this->drop($f, $student->id, $section->id);
        self::assertSame("reversed", $receipt["course_charge"]);
        self::assertSame(5000, $f->accounts->get($id)->balance()->minor);
        self::assertSame(2, count($f->accounts->get($id)->courseCharges($enrollment->id)));
        foreach ($f->accounts->get($id)->courseCharges($enrollment->id) as $charge) {
            self::assertTrue($f->accounts->get($id)->entry($charge)["reversed"]);
        }
        $f->service->register($student->id, $section->id);
        self::assertSame(36000, $f->accounts->get($id)->balance()->minor);
    }

    public function testLateDropKeepsTheRegistrationRateAndSectionCharge(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $this->rate($f, 10000);
        $section = $f->section(fee: 1000);
        $f->service->register($student->id, $section->id);
        $f->legacyGrade(
            new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "W", 0, false, false),
        );
        $f->legacyGrade(
            new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "D", 1000, true, true),
        );
        $f->clock->time = new \DateTimeImmutable("2026-08-20T12:00:00-07:00");
        $receipt = $this->drop($f, $student->id, $section->id);
        self::assertFalse($receipt["academic_records_removed"]);
        self::assertSame(
            31000,
            $f->accounts->get($this->account($f, $student->id))->balance()->minor,
        );
    }

    public function testWithdrawalScheduleCreditsEachOriginalRegistrationChargeAndExcludesFlatTermFees(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $this->rate($f, 10001);
        $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            'Fictional flat fee',
            'fee',
            new Money(5000, 'USD'),
            'Fictional approved flat term fee.'
        );
        $section = $f->section(fee: 1001);
        $f->service->register($student->id, $section->id);
        $f->legacyGrade(new \Domain\Gradebook\GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        $f->publishDropPolicy($f->term, -1, new \Domain\Registration\ValueObject\DropWindows([
            ['through' => null, 'grade_code' => 'W', 'credit_basis_points' => 5000],
        ]));
        $id = $this->account($f, $student->id);
        self::assertSame(36004, $f->accounts->get($id)->balance()->minor);
        $f->clock->time = new \DateTimeImmutable('2026-08-20T12:00:00-07:00');
        $receipt = $this->drop($f, $student->id, $section->id);
        self::assertSame(
            ['basis_points' => 5000, 'minor' => 15503, 'currency' => 'USD', 'charge_count' => 2],
            $receipt['withdrawal_credit']
        );
        self::assertSame(20501, $f->accounts->get($id)->balance()->minor);
        self::assertFalse($receipt['cash_refund_issued']);
    }

    public function testProgramScopesAndTermBatchesDoNotRetrospectivelyBillRegistrations(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(fee: 0);
        $f->service->register($student->id, $section->id);
        $this->rate($f, 10000, $f->program);
        $review = $f
            ->billingBatches()
            ->review($f->term, [$student->id], "Fictional review after rate setup.");
        self::assertSame(0, $review["new_charges"]);
        self::assertSame("registration", $review["students"][0]["items"][0]["status"]);
        self::assertSame("registered_credit", $review["rules"][0]["basis"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        $second = $f->section(fee: 0);
        $f->service->register($student->id, $second->id);
        self::assertSame(
            30000,
            $f->accounts->get($this->account($f, $student->id))->balance()->minor,
        );
    }

    public function testCurrencyFailureRollsBackSeatsAcademicFactsAccountsAndAllRates(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(fee: 1000);
        $this->rate($f, 10000, currency: "EUR");
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_account_balances",
            "sis_audit",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        try {
            $f->service->register($student->id, $section->id);
            self::fail("Mismatched registration currencies were accepted.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }

    public function testFractionalCreditRoundingUsesIntegerMinorUnitsForEveryCurrency(): void
    {
        foreach (["USD", "JPY", "KWD"] as $currency) {
            $price = new RegistrationPrice(
                EntityId::generate(),
                EntityId::generate(),
                "Fictional rate",
                1500,
                new Money(101, $currency),
            );
            self::assertSame(152, $price->amount->minor);
            self::assertSame(
                $price->values(),
                RegistrationPrice::fromRecorded($price->values())->values(),
            );
            self::assertSame(
                0,
                new RegistrationPrice(
                    EntityId::generate(),
                    EntityId::generate(),
                    "Fictional rate",
                    0,
                    new Money(101, $currency),
                )->amount->minor,
            );
        }
    }

    public function testReadModelUpgradePreservesProtectedFinancialFactsAndInitializesKnownFlatBasis(): void
    {
        $f = new SisFixture();
        $rule = $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional earlier flat fee",
            "fee",
            new Money(250, "USD"),
            "Fictional earlier flat decision.",
        );
        $events = $f->financialEvents();
        $operations = $f->sql->rows("sis_history_operations");
        $f->sql->update(
            "sis_billing_rules",
            ["pricing_basis" => null],
            ["id" => (string) $rule->aggregateId()],
        );
        $migration = new \AddSisBillingPricing("pricing");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
                "sis.history_codec" => new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($f->connection->queryBuilder()),
                ),
            ]),
        );
        $migration->up();
        self::assertSame(
            "term",
            $f->sql->one("sis_billing_rules", ["id" => (string) $rule->aggregateId()])[
                "pricing_basis"
            ],
        );
        self::assertSame($events, $f->financialEvents());
        self::assertSame($operations, $f->sql->rows("sis_history_operations"));
    }

    public function testCreationRetryCannotChangePricingBasis(): void
    {
        $f = new SisFixture();
        $rule = $this->rate($f);
        $this->expectException(ConcurrentModificationException::class);
        $this->rules($f)->define(
            new EntityId((string) $rule->aggregateId()),
            $f->term,
            null,
            "Fictional tuition",
            "tuition",
            new Money(12500, "USD"),
            "Fictional different basis.",
            BillingBasis::Term,
        );
    }
    public function testMixedFutureRateCurrenciesBlockBillingWithoutOpeningAnAccount(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $this->rate($f);
        $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional term fee",
            "fee",
            new Money(250, "EUR"),
            "Fictional inconsistent setup.",
        );
        $events = $f->financialEvents();
        $review = $f
            ->billingBatches()
            ->review($f->term, [$student->id], "Fictional currency review.");
        self::assertSame(1, $review["blocked"]);
        self::assertSame([], $review["totals"]);
        self::assertSame($events, $f->financialEvents());
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }
    public function testRateAndFlatAmountLabelsAreSearchableWithoutChangingHistory(): void
    {
        $f = new SisFixture();
        $rate = $this->rate($f);
        $flat = $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional flat fee",
            "fee",
            new Money(250, "USD"),
            "Fictional flat setup.",
        );
        $events = $f->financialEvents();
        $directory = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository(
            $f->sql,
            $f->gradeCatalog(),
            $f->clock,
        );
        self::assertSame(
            (string) $rate->aggregateId(),
            $directory->records("billing", null, "Per credit", null)["items"][0]["id"],
        );
        self::assertSame(
            (string) $flat->aggregateId(),
            $directory->records("billing", null, "Per term", null)["items"][0]["id"],
        );
        self::assertSame($events, $f->financialEvents());
    }
}
