<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PaymentMethodReportingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(SisFixture $s): RelationalReportService
    {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }

    private function account(SisFixture $s, string $currency = "USD"): StudentAccount
    {
        return StudentAccount::open(new AccountId(), $s->student()->id, $s->term, $currency);
    }

    private function payment(
        SisFixture $s,
        StudentAccount $account,
        ?PaymentMethod $method,
        int $minor = 1000,
    ): EntityId {
        $id = EntityId::generate();
        $account->post(
            $id,
            EntryType::Payment,
            new Money($minor, $account->balance()->currency),
            $s->access->actor,
            staffRecorded: true,
            paymentDetails: new PaymentDetails($method, "FICTIONAL-PRIVATE-RECEIPT"),
        );
        return $id;
    }

    private function definition(): array
    {
        return [
            "dataset" => "postings",
            "columns" => [
                ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
                ["field" => "posting_payment_method", "label" => "Method", "aggregate" => "none"],
                ["field" => "posting_amount", "label" => "Payments", "aggregate" => "sum"],
            ],
            "filters" => [
                "condition" => "AND",
                "rules" => [
                    ["id" => "posting_reversed", "operator" => "equal", "value" => false],
                    ["id" => "posting_type", "operator" => "equal", "value" => "payment"],
                ],
            ],
        ];
    }

    private function change(
        SisFixture $s,
        StudentAccount $account,
        EntityId $entry,
        ?PaymentMethod $method,
    ): void {
        $account->changePaymentDetails(
            $entry,
            EntityId::generate(),
            new PaymentDetails($method),
            $s->access->actor,
            str_repeat("a", 64),
        );
        $s->saveAccount($account);
    }

    private function journal(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
        ]);
    }

    public function testEveryMethodAndUnknownCanBeReportedWithoutReceiptReferences(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        foreach ([...PaymentMethod::cases(), null] as $method) {
            $this->payment($s, $account, $method);
        }
        $s->saveAccount($account);
        $rows = $this->service($s)->preview($this->definition(), 0)["items"];
        self::assertCount(count(PaymentMethod::cases()) + 1, $rows);
        foreach ($rows as $row) {
            self::assertEquals(10.0, $row["c2"]);
        }
        self::assertContains(null, array_column($rows, "c1"));
        $stored = json_encode($s->sql->rows("sis_account_entries"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("FICTIONAL-PRIVATE-RECEIPT", $stored);
        $schema = AcademicReportSchema::datasets()["postings"];
        self::assertCount(
            count(PaymentMethod::cases()),
            $schema["fields"]["posting_payment_method"]["choices"],
        );
        self::assertArrayNotHasKey("payment_reference", $schema["fields"]);
        self::assertStringNotContainsString("previous_details", json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function testCorrectionsFollowOriginalAndReplacementFamilyWithoutMovingMoney(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $original = $this->payment($s, $account, PaymentMethod::Check);
        $s->saveAccount($account);
        $replacement = EntityId::generate();
        $account->correct(
            $original,
            $replacement,
            new Money(1500, "USD"),
            $s->access->actor,
            str_repeat("b", 64),
            null,
        );
        $s->saveAccount($account);
        $before = $s->sql->one("sis_account_balances", ["id" => (string) $account->aggregateId()]);
        $this->change($s, $account, $original, PaymentMethod::Scholarship);
        self::assertSame(
            ["scholarship", "scholarship"],
            array_column($s->sql->rows("sis_account_entries"), "payment_method"),
        );
        $after = $s->sql->one("sis_account_balances", ["id" => (string) $account->aggregateId()]);
        unset($before["position"], $after["position"]);
        self::assertSame($before, $after);
        $rows = $this->service($s)->preview($this->definition(), 0)["items"];
        self::assertSame("scholarship", $rows[0]["c1"]);
        self::assertEquals(15.0, $rows[0]["c2"]);
        $this->change($s, $account, $replacement, null);
        self::assertSame([null, null], array_column($s->sql->rows("sis_account_entries"), "payment_method"));
        self::assertNull($this->service($s)->preview($this->definition(), 0)["items"][0]["c1"]);
    }

    public function testSeveralReceiptAndAmountChangesCanBeProjectedInOneAtomicSave(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $entry = $this->payment($s, $account, PaymentMethod::Cash);
        $s->saveAccount($account);
        $account->changePaymentDetails(
            $entry,
            EntityId::generate(),
            new PaymentDetails(PaymentMethod::Check),
            $s->access->actor,
            str_repeat("c", 64),
        );
        $replacement = EntityId::generate();
        $account->correct(
            $entry,
            $replacement,
            new Money(1500, "USD"),
            $s->access->actor,
            str_repeat("d", 64),
            null,
        );
        $account->changePaymentDetails(
            $replacement,
            EntityId::generate(),
            new PaymentDetails(PaymentMethod::Loan),
            $s->access->actor,
            str_repeat("e", 64),
        );
        $s->saveAccount($account);
        self::assertSame(
            ["loan", "loan"],
            array_column($s->sql->rows("sis_account_entries"), "payment_method"),
        );
        self::assertSame(
            -1500,
            (int) $s->sql->one("sis_account_balances", ["id" => (string) $account->aggregateId()])[
                "balance_minor"
            ],
        );
    }

    public function testReversedRefundMethodDescribesItsOwnOutgoingReceipt(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $this->payment($s, $account, PaymentMethod::Loan, 2000);
        $refund = EntityId::generate();
        $account->post(
            $refund,
            EntryType::Refund,
            new Money(500, "USD"),
            $s->access->actor,
            staffRecorded: true,
            paymentDetails: new PaymentDetails(PaymentMethod::Check),
        );
        $s->saveAccount($account);
        $account->reverse($refund, $s->access->actor);
        $s->saveAccount($account);
        $this->change($s, $account, $refund, PaymentMethod::BankTransfer);
        $rows = $s->sql->rows("sis_account_entries");
        self::assertSame(["loan", "bank_transfer"], array_column($rows, "payment_method"));
        self::assertSame(1, (int) $rows[1]["reversed"]);
        self::assertSame(-2000, $account->balance()->minor);
    }

    public function testTamperedMethodPreventsCorrectionAndRollsBackTheCoupledJournal(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $entry = $this->payment($s, $account, PaymentMethod::Cash);
        $s->saveAccount($account);
        $s->sql->update(
            "sis_account_entries",
            ["payment_method" => "loan"],
            ["operation_id" => $entry->value],
        );
        $before = $this->journal($s);
        $balance = $s->sql->rows("sis_account_balances");
        try {
            $this->change($s, $account, $entry, PaymentMethod::Grant);
            self::fail("A drifted receipt-method projection was silently repaired by a new decision.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->journal($s));
            self::assertSame($balance, $s->sql->rows("sis_account_balances"));
        }
    }

    public function testUpgradeDerivesKnownMethodsFromProtectedEventsAndKeepsOriginalJournal(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $entry = $this->payment($s, $account, PaymentMethod::Check);
        $this->payment($s, $account, null);
        $s->saveAccount($account);
        $this->change($s, $account, $entry, PaymentMethod::Grant);
        $s->sql->update(
            "sis_account_entries",
            ["payment_method" => null],
            ["account_id" => (string) $account->aggregateId()],
        );
        $before = $this->journal($s);
        $upgrade = new FinancialProjectionUpgrade(
            $s->connection->queryBuilder(),
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \EventStoreFixture::config($s->connection->queryBuilder()),
            ),
        );
        $upgrade->run("student_account", entries: true, paymentMethods: true);
        self::assertSame(
            ["grant", null],
            array_column($s->sql->rows("sis_account_entries"), "payment_method"),
        );
        self::assertSame($before, $this->journal($s));
        $upgrade->run("student_account", entries: true, paymentMethods: true);
        self::assertSame($before, $this->journal($s));
    }

    public function testSummariesKeepCurrenciesAndUnknownMethodsSeparateAndRejectFactMultiplication(): void
    {
        $s = new SisFixture();
        foreach (["USD", "EUR"] as $currency) {
            $account = $this->account($s, $currency);
            $this->payment($s, $account, PaymentMethod::Grant);
            $this->payment($s, $account, PaymentMethod::Grant, 2000);
            $this->payment($s, $account, PaymentMethod::Other, 3000);
            $this->payment($s, $account, null, 4000);
            $s->saveAccount($account);
        }
        $definition = $this->definition();
        $rows = $this->service($s)->preview($definition, 0)["items"];
        self::assertCount(6, $rows);
        $unknown = array_values(array_filter($rows, static fn(array $r): bool => $r["c1"] === null));
        self::assertCount(2, $unknown);
        self::assertEquals([40.0, 40.0], array_column($unknown, "c2"));
        $missing = $definition;
        $missing["filters"]["rules"][] = [
            "id" => "posting_payment_method",
            "operator" => "is_null",
            "value" => null,
        ];
        self::assertCount(2, $this->service($s)->preview($missing, 0)["items"]);
        $other = $definition;
        $other["filters"]["rules"][] = [
            "id" => "posting_payment_method",
            "operator" => "equal",
            "value" => "other",
        ];
        self::assertEquals([30.0, 30.0], array_column($this->service($s)->preview($other, 0)["items"], "c2"));
        $definition["joins"] = ["registration" => "left"];
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($definition, 0);
    }

    public function testStaticMethodsStayCapturedWhileDynamicReportsAndSourcePermissionsStayCurrent(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $entry = $this->payment($s, $account, PaymentMethod::Cash);
        $s->saveAccount($account);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, "Fictional payment methods", $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, "static", null);
        $this->change($s, $account, $entry, PaymentMethod::Scholarship);
        self::assertSame("scholarship", $service->preview($this->definition(), 0)["items"][0]["c1"]);
        self::assertSame("cash", $service->run($id, 0)["items"][0]["c1"]);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run", "sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testConditionalFundingTotalsAndLatestEligiblePaymentShareTheTypedMethodField(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $this->payment($s, $account, PaymentMethod::Grant, 1000);
        $this->payment($s, $account, PaymentMethod::Loan, 2000);
        $this->payment($s, $account, PaymentMethod::Cash, 3000);
        $s->saveAccount($account);
        $definition = $this->definition();
        $definition["columns"] = [$definition["columns"][0]];
        foreach (["grant", "loan"] as $method) {
            $definition["columns"][] = [
                "field" => "posting_amount",
                "label" => $method,
                "aggregate" => "sum",
                "conditions" => [
                    "condition" => "AND",
                    "rules" => [
                        ["id" => "posting_payment_method", "operator" => "equal", "value" => $method],
                    ],
                ],
            ];
        }
        $rows = $this->service($s)->preview($definition, 0)["items"];
        self::assertEquals([["c0" => "USD", "c1" => 10.0, "c2" => 20.0]], $rows);
        $definition = [
            "dataset" => "accounts",
            "columns" => [
                ["field" => "posting_payment_method", "label" => "Method", "aggregate" => "none"],
                ["field" => "posting_amount", "label" => "Amount", "aggregate" => "none"],
                ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
            ],
            "record_selection" => [
                "entry" => [
                    "date_field" => "posting_recorded_at",
                    "direction" => "latest",
                    "ties" => "one",
                ],
            ],
            "join_filters" => [
                "entry" => [
                    "condition" => "AND",
                    "rules" => [
                        ["id" => "posting_type", "operator" => "equal", "value" => "payment"],
                        ["id" => "posting_reversed", "operator" => "equal", "value" => false],
                        ["id" => "posting_payment_method", "operator" => "equal", "value" => "grant"],
                    ],
                ],
            ],
        ];
        self::assertEquals(
            [["c0" => "grant", "c1" => 10.0, "c2" => "USD"]],
            $this->service($s)->preview($definition, 0)["items"],
        );
    }

    public function testConflictingUpgradeRollsBackEarlierEnrichmentAndNeverRepairsWrongKnownMethods(): void
    {
        $s = new SisFixture();
        $accounts = [$this->account($s), $this->account($s)];
        foreach ($accounts as $account) {
            $this->payment($s, $account, PaymentMethod::Loan);
            $s->saveAccount($account);
        }
        $s->sql->update(
            "sis_account_entries",
            ["payment_method" => null],
            ["account_id" => (string) $accounts[0]->aggregateId()],
        );
        $s->sql->update(
            "sis_account_entries",
            ["payment_method" => "cash"],
            ["account_id" => (string) $accounts[1]->aggregateId()],
        );
        $before = $this->journal($s);
        $rows = $s->sql->rows("sis_account_entries");
        $upgrade = new FinancialProjectionUpgrade(
            $s->connection->queryBuilder(),
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \EventStoreFixture::config($s->connection->queryBuilder()),
            ),
        );
        try {
            $upgrade->run("student_account", entries: true, paymentMethods: true);
            self::fail("A conflicting method allowed partial account enrichment.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->journal($s));
            self::assertSame($rows, $s->sql->rows("sis_account_entries"));
        }
    }

    public function testNullableMethodProjectionCompilesAcrossFiveFrameworkDialects(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("getColumns")->willReturn([]);
        $schema
            ->method("alter")
            ->willReturnCallback(static function (
                string $name,
                callable $callback
            ) use (
                &$definitions,
            ): void {
                $table = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($table);
                $definitions[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
        $migration = new \AddSisPaymentMethodReports("payment-method-projection");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        self::assertCount(1, $definitions);
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $sql = json_encode(new $class($connection)->alter($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("payment_method", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression("/CREATE TABLE|DELETE FROM|DROP TABLE/i", $sql);
        }
        $query = new ReportQuery($this->definition(), AcademicReportSchema::datasets()["postings"]);
        self::assertStringContainsString(
            "payment_method",
            new ReportSelectCompiler($s->sql)->compile($query)["sql"],
        );
    }
}
