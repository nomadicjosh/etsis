<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalFinancialFixture;

final class AccountChargeReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/HistoricalFinancialFixture.php';
    }

    private function definition(bool $historical = false, string $cutoff = '2026-08-04T10:00:00Z'): array
    {
        return [
            'dataset' => $historical ? 'historical_accounts' : 'accounts',
            ...($historical ? ['financial_as_of' => $cutoff] : []),
            'columns' => array_map(static fn(string $key): array => [
                'field' => $key, 'label' => $key, 'aggregate' => 'none',
            ], ['account_id', 'currency', 'tuition_minor', 'fees_minor', 'manual_charges_minor',
                'unclassified_charges_minor', 'charges_minor', 'payments_minor']),
        ];
    }

    private function timeline(): array
    {
        $f = new HistoricalFinancialFixture();
        $a = $f->account();
        $actor = $f->sis->access->actor;
        $tuition = EntityId::generate();
        $manual = EntityId::generate();
        $a->post(
            $tuition,
            EntryType::Charge,
            new Money(10000, 'USD'),
            $actor,
            chargeSource: ChargeSource::TermTuition
        );
        $a->post(
            EntityId::generate(),
            EntryType::Charge,
            new Money(1500, 'USD'),
            $actor,
            chargeSource: ChargeSource::TermFee
        );
        $a->post(
            EntityId::generate(),
            EntryType::Charge,
            new Money(500, 'USD'),
            $actor,
            chargeSource: ChargeSource::Section
        );
        $a->post($manual, EntryType::Charge, new Money(300, 'USD'), $actor, staffRecorded: true);
        $a->post(EntityId::generate(), EntryType::Charge, new Money(200, 'USD'), $actor);
        $f->save($a, '2026-08-01T10:00:00Z');
        foreach ([100, 200, 300] as $minor) {
            $a->post(EntityId::generate(), EntryType::Payment, new Money($minor, 'USD'), $actor);
        }
        $f->save($a, '2026-08-02T10:00:00Z');
        $replacement = EntityId::generate();
        $a->correct($manual, $replacement, new Money(500, 'USD'), $actor, str_repeat('a', 64), null);
        $f->save($a, '2026-08-03T10:00:00Z');
        $a->creditCharge($replacement, EntityId::generate(), new Money(200, 'USD'), $actor, str_repeat('b', 64));
        $f->save($a, '2026-08-04T10:00:00Z');
        return [$f, $a, $replacement];
    }

    public function testIndependentComponentsReconcileChargesWithoutMultiplyingPaymentsOrWritingFacts(): void
    {
        [$f] = $this->timeline();
        $before = array_map($f->sis->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_account_entries', 'sis_account_balances', 'sis_account_entry_heads']);
        $row = $f->reports()->preview($this->definition(), 0)['items'][0];
        self::assertSame([10000, 2000, 300, 200, 12500, 600], array_slice(array_values($row), 2));
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $q = new ReportQuery($this->definition(), AcademicReportSchema::datasets()['accounts']);
        self::assertCount(1, new SqlRelationalReportSource($f->sis->sql)->execute($q, 0));
        self::assertSame($before, array_map($f->sis->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_account_entries', 'sis_account_balances', 'sis_account_entry_heads']));
    }

    public function testCutoffsRetainOriginalCorrectionCreditAndReversalChronology(): void
    {
        [$f, $a, $replacement] = $this->timeline();
        $a->reverse($replacement, $f->sis->access->actor);
        $f->save($a, '2026-08-05T10:00:00Z');
        foreach (
            [
            ['2026-08-01T09:59:59Z', null], ['2026-08-01T10:00:00Z', 300],
            ['2026-08-03T09:59:59Z', 300], ['2026-08-03T10:00:00Z', 500],
            ['2026-08-04T10:00:00Z', 300], ['2026-08-05T10:00:00Z', 0],
            ] as [$cutoff, $manual]
        ) {
            $rows = $f->reports()->preview($this->definition(true, $cutoff), 0)['items'];
            self::assertSame($manual, $rows[0]['c4'] ?? null, $cutoff);
            if ($manual !== null) {
                self::assertSame(10000, $rows[0]['c2']);
                self::assertSame(2000, $rows[0]['c3']);
                self::assertSame($rows[0]['c6'], array_sum(array_slice(array_values($rows[0]), 2, 4)));
            }
        }
        self::assertSame(0, $f->reports()->preview($this->definition(), 0)['items'][0]['c4']);
    }

    public function testEmptyAccountsHaveZeroComponentsAndUnknownSourcesStayUnclassified(): void
    {
        $f = new HistoricalFinancialFixture();
        $a = $f->account();
        $f->save($a, '2026-08-01T10:00:00Z');
        foreach ([false, true] as $historical) {
            $row = $f->reports()->preview($this->definition($historical, '2026-08-01T10:00:00Z'), 0)['items'][0];
            self::assertSame([0, 0, 0, 0, 0, 0], array_slice(array_values($row), 2));
        }
        $a->post(EntityId::generate(), EntryType::Charge, new Money(700, 'USD'), $f->sis->access->actor);
        $f->save($a, '2026-08-02T10:00:00Z');
        $row = $f->reports()->preview($this->definition(), 0)['items'][0];
        self::assertSame([0, 0, 0, 700, 700, 0], array_slice(array_values($row), 2));
        self::assertNull($f->sis->sql->rows('sis_account_entries')[0]['charge_source']);
    }

    public function testCurrencySummariesAndCompatibleCalculationsKeepExactDeclaredUnits(): void
    {
        $f = new HistoricalFinancialFixture();
        foreach (['USD', 'JPY', 'BHD'] as $currency) {
            $a = $f->account($currency);
            $a->post(
                EntityId::generate(),
                EntryType::Charge,
                new Money(1234, $currency),
                $f->sis->access->actor,
                chargeSource: ChargeSource::TermTuition
            );
            $a->post(
                EntityId::generate(),
                EntryType::Charge,
                new Money(66, $currency),
                $f->sis->access->actor,
                chargeSource: ChargeSource::TermFee
            );
            $f->save($a, '2026-08-01T10:00:00Z');
        }
        foreach ([false, true] as $historical) {
            $d = $this->definition($historical, '2026-08-01T10:00:00Z');
            $d['columns'] = [
                ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
                ['field' => 'tuition', 'label' => 'Tuition and fees', 'aggregate' => 'sum',
                    'calculation' => ['operator' => '+', 'field' => 'fees']],
                ['field' => 'tuition_minor', 'label' => 'Tuition minor', 'aggregate' => 'sum'],
            ];
            $rows = array_column($f->reports()->preview($d, 0)['items'], null, 'c0');
            foreach (['USD' => 13.0, 'JPY' => 1300.0, 'BHD' => 1.3] as $currency => $expected) {
                self::assertEqualsWithDelta($expected, $rows[$currency]['c1'], 0.000000001);
                self::assertSame(1234, $rows[$currency]['c2']);
            }
            array_shift($d['columns']);
            try {
                $f->reports()->preview($d, 0);
                self::fail('A component summary omitted Currency.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMatchingPaymentsDoNotNarrowChargesAndFanOutTotalsAreRejected(): void
    {
        [$f] = $this->timeline();
        $d = $this->definition();
        $d['columns'] = [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'tuition_minor', 'label' => 'Tuition', 'aggregate' => 'sum'],
            ['field' => 'fees_minor', 'label' => 'Fees', 'aggregate' => 'sum'],
        ];
        $d['existence'] = ['entry' => ['mode' => 'any', 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'posting_type', 'operator' => 'equal', 'value' => 'payment'],
        ]]]];
        $d['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c1', 'operator' => 'greater', 'value' => 9999],
        ]];
        $row = $f->reports()->preview($d, 0)['items'][0];
        self::assertSame([10000, 2000], [$row['c1'], $row['c2']]);
        $d['joins'] = ['entry' => 'left'];
        $this->expectException(ValidationException::class);
        $f->reports()->preview($d, 0);
    }

    public function testDynamicAndStaticSavedBreakdownsPreserveSourceAuthorityAndEarlierResults(): void
    {
        [$f, $a, $replacement] = $this->timeline();
        $reports = $f->reports();
        $dynamic = EntityId::generate();
        $static = EntityId::generate();
        $reports->save($dynamic, $dynamic, -1, 'Fictional current charge breakdown', $this->definition());
        $reports->save($static, $static, -1, 'Fictional retained charge breakdown', $this->definition());
        $reports->configureReport($static, EntityId::generate(), 0, 'static', null);
        $a->reverse($replacement, $f->sis->access->actor);
        $f->save($a, '2026-08-05T10:00:00Z');
        self::assertSame(0, $reports->run($dynamic, 0)['items'][0]['c4']);
        self::assertSame(300, $reports->run($static, 0)['items'][0]['c4']);
        $reader = $f->sis->user();
        new SqlReportRepository($f->sis->sql, $f->sis->clock)->share($static, $reader);
        $f->sis->access->actor = $reader;
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $reports->run($static, 0);
    }

    public function testIncompletePostingCheckpointCannotReturnPartialComponents(): void
    {
        [$f, $a] = $this->timeline();
        $f->sis->sql->update('sis_account_entry_heads', ['position' => 0], ['id' => (string) $a->aggregateId()]);
        $this->expectException(ValidationException::class);
        $f->reports()->preview($this->definition(), 0);
    }

    public function testLinkedTuitionAndFeeCreditsReduceTheirOwnBucketsAndReverseTogether(): void
    {
        $f = new HistoricalFinancialFixture();
        $a = $f->account();
        $tuition = EntityId::generate();
        $fee = EntityId::generate();
        $actor = $f->sis->access->actor;
        $a->post(
            $tuition,
            EntryType::Charge,
            new Money(10000, 'USD'),
            $actor,
            chargeSource: ChargeSource::TermTuition
        );
        $a->post($fee, EntryType::Charge, new Money(1000, 'USD'), $actor, chargeSource: ChargeSource::TermFee);
        $f->save($a, '2026-08-01T10:00:00Z');
        $a->creditCharge($tuition, EntityId::generate(), new Money(2000, 'USD'), $actor, str_repeat('a', 64));
        $a->creditCharge($fee, EntityId::generate(), new Money(100, 'USD'), $actor, str_repeat('b', 64));
        $f->save($a, '2026-08-02T10:00:00Z');
        foreach ([false, true] as $historical) {
            $row = $f->reports()->preview($this->definition($historical, '2026-08-02T10:00:00Z'), 0)['items'][0];
            self::assertSame([8000, 900, 8900], [$row['c2'], $row['c3'], $row['c6']]);
        }
        $a->reverse($fee, $actor);
        $f->save($a, '2026-08-03T10:00:00Z');
        $row = $f->reports()->preview($this->definition(), 0)['items'][0];
        self::assertSame([8000, 0, 8000], [$row['c2'], $row['c3'], $row['c6']]);
    }
}
