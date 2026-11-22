<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayObject;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Tests\Support\HistoricalFinancialFixture;
use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class HistoricalFinancialReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/HistoricalFinancialFixture.php";
    }
    private function timeline(): array
    {
        $f = new HistoricalFinancialFixture();
        $s = $f->sis;
        $a = $f->account();
        $charge = EntityId::generate();
        $payment = EntityId::generate();
        $replacement = EntityId::generate();
        $a->post(
            $charge,
            EntryType::Charge,
            new Money(10000, "USD"),
            $s->access->actor,
            staffRecorded: true,
        );
        $f->save($a, "2026-08-01T10:00:00Z");
        $a->post(
            $payment,
            EntryType::Payment,
            new Money(2500, "USD"),
            $s->access->actor,
            staffRecorded: true,
        );
        $f->save($a, "2026-08-02T10:00:00Z");
        $a->correct(
            $charge,
            $replacement,
            new Money(12000, "USD"),
            $s->access->actor,
            str_repeat("a", 64),
            null,
        );
        $f->save($a, "2026-08-03T10:00:00Z");
        $credit = EntityId::generate();
        $a->creditCharge(
            $replacement,
            $credit,
            new Money(2000, "USD"),
            $s->access->actor,
            str_repeat("b", 64),
        );
        $f->save($a, "2026-08-04T10:00:00Z");
        $a->reverse($replacement, $s->access->actor);
        $f->save($a, "2026-08-05T10:00:00Z");
        return [$f, $a, $charge, $payment, $replacement, $credit];
    }
    public function testHistoricalBalancesPreserveAmountsBeforeCorrectionAndLinkedReversals(): void
    {
        [$f] = $this->timeline();
        $before = $f->sis->sql->rows("sis_history_events");
        foreach (
            [
                ["2026-08-01T09:59:59Z", null],
                ["2026-08-01T10:00:00Z", 10000],
                ["2026-08-02T10:00:00Z", 7500],
                ["2026-08-03T09:59:59Z", 7500],
                ["2026-08-03T10:00:00Z", 9500],
                ["2026-08-04T10:00:00Z", 7500],
                ["2026-08-05T10:00:00Z", -2500],
            ] as [$date, $balance]
        ) {
            $r = $f->reports()->preview($f->definition(cutoff: $date), 0);
            self::assertSame($balance, $r["items"][0]["c2"] ?? null, $date);
            self::assertSame($date, $r["financial_as_of"]);
        }
        $r = $f->reports()->preview($f->definition(cutoff: "2026-08-04T10:00:00Z"), 0)["items"][0];
        self::assertSame([10000, 2500, 0], [$r["c3"], $r["c4"], $r["c5"]]);
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
    }
    public function testHistoricalPostingDetailIsActiveAtCutoffAndWithholdsLaterReceiptDetails(): void
    {
        [$f, , $charge, $payment, $replacement, $credit] = $this->timeline();
        foreach (
            [
                ["2026-08-02T10:00:00Z", [$charge->value, $payment->value]],
                ["2026-08-04T10:00:00Z", [$replacement->value, $payment->value, $credit->value]],
                ["2026-08-05T10:00:00Z", [$payment->value]],
            ] as [$date, $expected]
        ) {
            $result = $f->reports()->preview($f->definition("historical_postings", $date), 0);
            $rows = $result['items'];
            $actual = array_column($rows, "c0");
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual);
        }
        $fields = AcademicReportSchema::datasets()["historical_postings"]["fields"];
        foreach (
            [
                "posting_reversed",
                "posting_payment_method",
                "posting_corrected_by",
                "posting_corrects",
            ] as $key
        ) {
            self::assertArrayNotHasKey($key, $fields);
        }
        self::assertStringNotContainsString("reason", json_encode($fields));
    }
    public function testRefundTotalsUseTheirOwnRecordedAndReversalTimes(): void
    {
        $f = new HistoricalFinancialFixture();
        $s = $f->sis;
        $account = $f->account();
        $account->post(EntityId::generate(), EntryType::Payment, new Money(5000, 'USD'), $s->access->actor);
        $f->save($account, '2026-08-01T10:00:00Z');
        $refund = EntityId::generate();
        $account->post($refund, EntryType::Refund, new Money(1500, 'USD'), $s->access->actor);
        $f->save($account, '2026-08-02T10:00:00Z');
        $account->reverse($refund, $s->access->actor);
        $f->save($account, '2026-08-03T10:00:00Z');
        foreach ([1 => [-5000, 0], 2 => [-3500, 1500], 3 => [-5000, 0]] as $day => $expected) {
            $cutoff = '2026-08-0' . $day . 'T10:00:00Z';
            $result = $f->reports()->preview($f->definition(cutoff: $cutoff), 0);
            self::assertSame($expected, [$result['items'][0]['c2'], $result['items'][0]['c5']]);
            self::assertSame(5000, $result['items'][0]['c4']);
        }
    }

    public function testEmptyAccountsAreIncludedOnlyFromRecordedOpening(): void
    {
        $f = new HistoricalFinancialFixture();
        $a = $f->account();
        $f->save($a, "2026-08-03T10:00:00Z");
        self::assertSame(
            [],
            $f->reports()->preview($f->definition(cutoff: "2026-08-03T09:59:59Z"), 0)["items"],
        );
        self::assertSame(0, $f->reports()->preview($f->definition(), 0)["items"][0]["c2"]);
    }
    public function testCrossCurrencySummariesKeepExactUnitsAndRequireCurrency(): void
    {
        $f = new HistoricalFinancialFixture();
        foreach (["USD", "JPY", "KWD"] as $currency) {
            $a = $f->account($currency);
            $a->post(
                EntityId::generate(),
                EntryType::Charge,
                new Money(10000, $currency),
                $f->sis->access->actor,
            );
            $f->save($a, "2026-08-03T10:00:00Z");
        }
        $d = $f->definition();
        $d["columns"] = [
            ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
            ["field" => "balance", "label" => "Balance", "aggregate" => "sum"],
        ];
        $rows = array_column($f->reports()->preview($d, 0)["items"], "c1", "c0");
        self::assertSame(["JPY" => 10000.0, "KWD" => 10.0, "USD" => 100.0], $rows);
        array_shift($d["columns"]);
        $this->expectException(ValidationException::class);
        $f->reports()->preview($d, 0);
    }
    public function testHistoricalTotalsRejectOneToManyFanout(): void
    {
        [$f] = $this->timeline();
        $d = $f->definition();
        $schema = AcademicReportSchema::datasets()["historical_accounts"];
        $many = array_keys(
            array_filter(
                $schema["relationships"],
                static fn(array $r): bool => $r["many"] ?? false,
            ),
        )[0];
        $d["joins"] = [$many => "left"];
        $d["columns"] = [
            ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
            ["field" => "balance_minor", "label" => "Balance", "aggregate" => "sum"],
        ];
        $this->expectException(ValidationException::class);
        $f->reports()->preview($d, 0);
    }
    public function testMissingChronologyBlocksReportsWithoutRepairOrPartialTotals(): void
    {
        [$f, $a, $charge] = $this->timeline();
        $s = $f->sis;
        $events = $s->sql->rows("sis_history_events");
        $s->sql->update(
            "sis_account_entries",
            ["reversed_at" => null],
            ["operation_id" => $charge->value],
        );
        try {
            $f->reports()->preview($f->definition(), 0);
            self::fail("Missing chronology accepted");
        } catch (ValidationException $e) {
            self::assertStringContainsString("chronology", $e->errors[0]["message"]);
        }
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertNull(
            $s->sql->rows("sis_account_entries", ["operation_id" => $charge->value], 1)[0][
                "reversed_at"
            ],
        );
    }
    public function testVerifiedUpgradeFillsOnlyUnknownChronologyAndPreservesJournalAndAmounts(): void
    {
        [$f] = $this->timeline();
        $s = $f->sis;
        $events = array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
        ]);
        $before = $s->sql->rows("sis_account_entries");
        $heads = $s->sql->rows("sis_account_entry_heads");
        $s->sql->execute(
            "UPDATE sis_account_entries SET reversed_at = NULL, reversed_position = NULL",
        );
        $s->sql->execute(
            "UPDATE sis_account_entry_heads SET opened_at = NULL, temporal_position = NULL",
        );
        $migration = new \AddSisHistoricalAccountReports("historical-finance");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "test_migrations"),
                "sis.history_codec" => new ProtectedEventCodec(
                    \EventStoreFixture::config($s->connection->queryBuilder()),
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, $s->sql->rows("sis_account_entries"));
        self::assertSame($heads, $s->sql->rows("sis_account_entry_heads"));
        self::assertSame(
            $events,
            array_map($s->sql->rows(...), [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
            ]),
        );
    }
    public function testConflictingKnownChronologyStopsVerifiedUpgrade(): void
    {
        [$f, , $charge] = $this->timeline();
        $s = $f->sis;
        $s->sql->update(
            "sis_account_entries",
            ["reversed_at" => "2026-08-04 00:00:00"],
            ["operation_id" => $charge->value],
        );
        $this->expectException(CorruptHistoryException::class);
        new FinancialProjectionUpgrade(
            $s->connection->queryBuilder(),
            new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
        )->run("student_account", entries: true, temporal: true);
    }
    public function testBothStudentAndFinancePermissionsRemainRequired(): void
    {
        [$f] = $this->timeline();
        $s = $f->sis;
        $s->access->staff = false;
        foreach (
            [
                ["sis:reporting:manage", "sis:reporting:run", "sis:student:read"],
                ["sis:reporting:manage", "sis:reporting:run", "sis:finance:read"],
            ] as $grants
        ) {
            $s->access->grants = $grants;
            try {
                $f->reports()->preview($f->definition(), 0);
                self::fail("Missing source duty accepted");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testInvalidMissingAndFutureCutoffsAreRejected(): void
    {
        $f = new HistoricalFinancialFixture();
        foreach (
            [
                null,
                "",
                "2026-02-30T10:00:00Z",
                "2026-08-10T10:00:00-07:00",
                "2026-08-01' OR 1=1",
                "2027-01-01T00:00:00Z",
            ] as $cutoff
        ) {
            $d = $f->definition();
            $d["financial_as_of"] = $cutoff;
            try {
                $f->reports()->preview($d, 0);
                self::fail("Invalid cutoff accepted");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testSavedAndStaticReportsRetainTheirCutoffAndCapturedRows(): void
    {
        [$f] = $this->timeline();
        $s = $f->sis;
        $id = EntityId::generate();
        $definition = $f->definition(cutoff: "2026-08-03T09:59:59Z");
        $f->reports()->save(
            $id,
            EntityId::generate(),
            -1,
            "Fictional historical balances",
            $definition,
        );
        $live = $f->reports()->run($id, 0);
        self::assertSame([], $f->reports()->view($id)["student_actions"]);
        self::assertSame(7500, $live["items"][0]["c2"]);
        $configured = $s
            ->reportLifecycles()
            ->configure($id, EntityId::generate(), 0, "static", null);
        $before = $s->sql->rows("sis_history_events");
        $page = $f->reports()->run($id, 0);
        self::assertSame("2026-08-03T09:59:59Z", $page["financial_as_of"]);
        self::assertSame($live["items"], $page["items"]);
        $s->sql->update(
            "sis_programs",
            ["title" => "Changed current program"],
            ["id" => $s->program->value],
        );
        self::assertSame($page["items"], $f->reports()->run($id, 0)["items"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame("static", $configured["lifecycle"]["mode"]);
    }
    public function testCutoffCanBeIncludedInExportedColumnsAndUnsupportedSourcesRejectIt(): void
    {
        [$f] = $this->timeline();
        $d = $f->definition();
        $d["columns"][] = [
            "field" => "cutoff_utc",
            "label" => "Recorded cutoff UTC",
            "aggregate" => "none",
        ];
        self::assertSame("2026-08-03 10:00:00", $f->reports()->preview($d, 0)["items"][0]["c6"]);
        $d["dataset"] = "accounts";
        $this->expectException(ValidationException::class);
        $f->reports()->preview($d, 0);
    }
}
