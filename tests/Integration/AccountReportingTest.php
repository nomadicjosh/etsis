<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AccountReportingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
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
            $s->reportLifecycles()
        );
    }

    private function account(SisFixture $s, string $currency = 'USD', int $minor = 1200): StudentAccount
    {
        $account = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, $currency);
        if ($minor !== 0) {
            $account->post(
                EntityId::generate(),
                $minor > 0 ? EntryType::Charge : EntryType::Payment,
                new Money(abs($minor), $currency),
                $s->access->actor
            );
        }
        $s->saveAccount($account);
        return $account;
    }

    private function definition(): array
    {
        return ['dataset' => 'accounts', 'columns' => array_map(
            static fn(string $field): array => ['field' => $field, 'label' => $field, 'aggregate' => 'none'],
            ['account_id', 'full_name', 'term_code', 'currency', 'balance']
        ), 'sort' => []];
    }

    private function journal(SisFixture $s): array
    {
        return [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_history_streams'),
            $s->sql->rows('sis_history_operations')];
    }

    public function testBalanceProjectionTracksPaymentsRefundsReversalsAndExactRetries(): void
    {
        $s = new SisFixture();
        $account = $this->account($s, minor: 0);
        $id = (string) $account->aggregateId();
        self::assertSame(0, (int) $s->sql->one('sis_account_balances', ['id' => $id])['balance_minor']);
        $charge = EntityId::generate();
        $payment = EntityId::generate();
        $refund = EntityId::generate();
        $account->post($charge, EntryType::Charge, new Money(10000, 'USD'), $s->access->actor);
        $account->post($payment, EntryType::Payment, new Money(15000, 'USD'), $s->access->actor);
        $account->post($refund, EntryType::Refund, new Money(2000, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        self::assertSame(-3000, (int) $s->sql->one('sis_account_balances', ['id' => $id])['balance_minor']);
        $totals = $s->sql->one('sis_account_balances', ['id' => $id]);
        self::assertSame([10000, 15000, 2000], array_map(
            static fn(string $column): int => (int) $totals[$column],
            ['charges_minor', 'payments_minor', 'refunds_minor']
        ));
        $account->reverse($charge, $s->access->actor);
        $s->saveAccount($account);
        $before = $this->journal($s);
        $balance = $s->sql->one('sis_account_balances', ['id' => $id]);
        self::assertSame(-13000, (int) $balance['balance_minor']);
        self::assertSame(0, (int) $balance['charges_minor']);
        self::assertSame($account->playhead(), (int) $balance['position']);
        $account->reverse($charge, $s->access->actor);
        $s->saveAccount($account);
        self::assertSame($before, $this->journal($s));
        self::assertSame($balance, $s->sql->one('sis_account_balances', ['id' => $id]));
        self::assertSame(-13000, $s->accounts->get(new AccountId($id))->balance()->minor);
    }

    public function testEachEntryTypeCanBeReversedWithoutChangingAnotherCategory(): void
    {
        $s = new SisFixture();
        $account = $this->account($s, minor: 0);
        $charge = EntityId::generate();
        $payment = EntityId::generate();
        $refund = EntityId::generate();
        $account->post($charge, EntryType::Charge, new Money(10000, 'USD'), $s->access->actor);
        $account->post($payment, EntryType::Payment, new Money(15000, 'USD'), $s->access->actor);
        $account->post($refund, EntryType::Refund, new Money(2000, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $account->reverse($refund, $s->access->actor);
        $s->saveAccount($account);
        $row = $s->sql->one('sis_account_balances', ['id' => (string) $account->aggregateId()]);
        self::assertSame([10000, 15000, 0, -5000], array_map(
            static fn(string $column): int => (int) $row[$column],
            ['charges_minor', 'payments_minor', 'refunds_minor', 'balance_minor']
        ));
        $account->reverse($payment, $s->access->actor);
        $s->saveAccount($account);
        $row = $s->sql->one('sis_account_balances', ['id' => (string) $account->aggregateId()]);
        self::assertSame([10000, 0, 0, 10000], array_map(
            static fn(string $column): int => (int) $row[$column],
            ['charges_minor', 'payments_minor', 'refunds_minor', 'balance_minor']
        ));
        $account->reverse($charge, $s->access->actor);
        $s->saveAccount($account);
        $row = $s->sql->one('sis_account_balances', ['id' => (string) $account->aggregateId()]);
        self::assertSame(0, (int) $row['charges_minor']);
        self::assertSame(0, (int) $row['balance_minor']);
    }

    public function testTotalsCanExceedOnePostingLimitWhileBalanceStillUsesItsOriginalBound(): void
    {
        $s = new SisFixture();
        $account = $this->account($s, minor: 0);
        for ($i = 0; $i < 3; ++$i) {
            $account->post(
                EntityId::generate(),
                EntryType::Charge,
                new Money(Money::MAX_MINOR, 'USD'),
                $s->access->actor
            );
            $account->post(
                EntityId::generate(),
                EntryType::Payment,
                new Money(Money::MAX_MINOR, 'USD'),
                $s->access->actor
            );
        }
        $s->saveAccount($account);
        $row = $s->sql->one('sis_account_balances', ['id' => (string) $account->aggregateId()]);
        self::assertSame(6000000000, (int) $row['charges_minor']);
        self::assertSame(6000000000, (int) $row['payments_minor']);
        self::assertSame(0, (int) $row['balance_minor']);
        $q = $this->definition();
        $q['columns'][4]['field'] = 'charges';
        self::assertSame(60000000.0, $this->service($s)->preview($q, 0)['items'][0]['c4']);
    }

    public function testMissingOrConflictingTotalsStopWritesAndLiveOrStaticReports(): void
    {
        foreach ([null, 999] as $bad) {
            $s = new SisFixture();
            $account = $this->account($s);
            $id = EntityId::generate();
            $service = $this->service($s);
            $service->save($id, $id, -1, 'Fictional account totals', $this->definition());
            $before = $this->journal($s);
            $s->sql->update(
                'sis_account_balances',
                ['charges_minor' => $bad],
                ['id' => (string) $account->aggregateId()]
            );
            try {
                $service->configureReport($id, EntityId::generate(), 0, 'static', null);
                self::fail('An incomplete total was saved as a fixed report.');
            } catch (ValidationException) {
                self::assertFalse($s->connection->pdo->inTransaction());
                self::assertSame('dynamic', $service->reportSettings($id)['lifecycle']['mode']);
            }
            $this->invalid($s, $this->definition());
            $account->post(EntityId::generate(), EntryType::Charge, new Money(1, 'USD'), $s->access->actor);
            try {
                $s->saveAccount($account);
                self::fail('An ordinary write repaired an unverified total.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->journal($s));
            }
        }
    }

    public function testFailedProjectionRollsBackTheOpeningMetadataEventsHeadsAndReceipts(): void
    {
        $s = new SisFixture();
        $s->connection->pdo->exec("CREATE TRIGGER fail_balance BEFORE INSERT ON sis_account_balances "
            . "BEGIN SELECT RAISE(ABORT, 'Fictional balance failure'); END");
        $before = $this->journal($s);
        try {
            $this->account($s);
            self::fail('A failed balance projection committed financial events.');
        } catch (\PDOException) {
            self::assertSame([], $s->sql->rows('sis_student_accounts'));
            self::assertSame([], $s->sql->rows('sis_account_balances'));
            self::assertSame($before, $this->journal($s));
        }
    }

    public function testMissingStaleOrAlteredProjectionStopsWritesAndMissingOrStaleReportsReturnNoPartialData(): void
    {
        foreach (['missing', 'position', 'amount'] as $case) {
            $s = new SisFixture();
            $account = $this->account($s);
            $id = (string) $account->aggregateId();
            $before = $this->journal($s);
            if ($case === 'missing') {
                $s->connection->pdo->exec('DELETE FROM sis_account_balances');
            } else {
                $s->sql->update('sis_account_balances', $case === 'position' ? ['position' => 1]
                    : ['balance_minor' => 999], ['id' => $id]);
            }
            $account->post(EntityId::generate(), EntryType::Charge, new Money(1, 'USD'), $s->access->actor);
            try {
                $s->saveAccount($account);
                self::fail('A financial write silently repaired an inconsistent balance.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->journal($s));
            }
            if ($case !== 'amount') {
                try {
                    $this->service($s)->preview($this->definition(), 0);
                    self::fail('An incomplete financial projection returned a partial report.');
                } catch (ValidationException) {
                    self::assertSame($before, $this->journal($s));
                }
            }
        }
    }

    public function testMigrationReconstructsRealHistoryAndPreservesEventsAndRetryEvidence(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $b = $this->account($s, 'JPY', -1000);
        $a->reverse(new EntityId($a->entries()[0]['operation_id']), $s->access->actor);
        $s->saveAccount($a);
        $balances = $s->sql->rows('sis_account_balances');
        $before = $this->journal($s);
        $s->connection->pdo->exec('DROP TABLE sis_account_balances');
        $migration = new \CreateSisAccountBalances('account-balances');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'test_migrations'
            ),
            'sis.history_codec' => new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
        ]));
        $migration->up();
        $migration->up();
        self::assertEqualsCanonicalizing($balances, $s->sql->rows('sis_account_balances'));
        self::assertSame($before, $this->journal($s));
        self::assertSame(-1000, $s->accounts->get(new AccountId((string) $b->aggregateId()))->balance()->minor);
    }

    public function testMigrationRejectsUnverifiableHistoryAndRollsBackEarlierReconstruction(): void
    {
        $s = new SisFixture();
        $this->account($s);
        $b = $this->account($s);
        $s->connection->pdo->exec('DELETE FROM sis_account_balances');
        $before = $this->journal($s);
        $s->sql->update('sis_student_accounts', ['currency' => 'EUR'], ['id' => (string) $b->aggregateId()]);
        try {
            new FinancialProjectionUpgrade(
                $s->connection->queryBuilder(),
                new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder()))
            )->run('student_account');
            self::fail('A projection was invented from metadata inconsistent with the recorded opening.');
        } catch (CorruptHistoryException) {
            self::assertSame([], $s->sql->rows('sis_account_balances'));
            self::assertSame($before, $this->journal($s));
        }
    }

    private function oldBalanceSchema(SisFixture $s): void
    {
        $rows = $s->sql->rows('sis_account_balances');
        $s->connection->pdo->exec('DROP TABLE sis_account_balances');
        $s->connection->pdo->exec('CREATE TABLE sis_account_balances '
            . '(id VARCHAR(26) PRIMARY KEY, balance_minor INTEGER NOT NULL, position INTEGER NOT NULL, '
            . 'FOREIGN KEY (id) REFERENCES sis_student_accounts(id))');
        foreach ($rows as $row) {
            $s->sql->insert('sis_account_balances', array_intersect_key(
                $row,
                array_flip(['id', 'balance_minor', 'position'])
            ));
        }
    }

    private function totalsMigration(SisFixture $s): \AddSisAccountTotals
    {
        $migration = new \AddSisAccountTotals('account-totals');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'test_migrations'
            ),
            'sis.history_codec' => new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
        ]));
        return $migration;
    }

    public function testTotalsUpgradeConvertsOnlyVerifiedRealPostingsAndCanBeRepeated(): void
    {
        $s = new SisFixture();
        $a = $this->account($s, minor: 1200);
        $b = $this->account($s, 'JPY', -1000);
        $a->reverse(new EntityId($a->entries()[0]['operation_id']), $s->access->actor);
        $s->saveAccount($a);
        $expected = $s->sql->rows('sis_account_balances');
        $before = $this->journal($s);
        $this->oldBalanceSchema($s);
        $this->totalsMigration($s)->up();
        $this->totalsMigration($s)->up();
        self::assertEqualsCanonicalizing($expected, $s->sql->rows('sis_account_balances'));
        self::assertSame($before, $this->journal($s));
        self::assertSame(-1000, $s->accounts->get(new AccountId((string) $b->aggregateId()))->balance()->minor);
    }

    public function testInterruptedTotalsUpgradeRetainsUnknownsAndCanResumeAfterSourceReview(): void
    {
        $s = new SisFixture();
        $this->account($s);
        $b = $this->account($s);
        $this->oldBalanceSchema($s);
        $before = $this->journal($s);
        $s->sql->update('sis_student_accounts', ['currency' => 'EUR'], ['id' => (string) $b->aggregateId()]);
        try {
            $this->totalsMigration($s)->up();
            self::fail('An inconsistent source produced a partially verified conversion.');
        } catch (CorruptHistoryException) {
            self::assertSame([null, null], array_column($s->sql->rows('sis_account_balances'), 'charges_minor'));
            self::assertSame($before, $this->journal($s));
        }
        $s->sql->update('sis_student_accounts', ['currency' => 'USD'], ['id' => (string) $b->aggregateId()]);
        $this->totalsMigration($s)->up();
        self::assertSame([1200, 1200], array_map(
            'intval',
            array_column($s->sql->rows('sis_account_balances'), 'charges_minor')
        ));
        self::assertSame($before, $this->journal($s));
    }

    public function testAConflictingKnownTotalIsNotSilentlyReplacedDuringConversion(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $s->sql->update(
            'sis_account_balances',
            ['charges_minor' => 1201, 'payments_minor' => 1],
            ['id' => (string) $account->aggregateId()]
        );
        $before = $s->sql->rows('sis_account_balances');
        $events = $this->journal($s);
        try {
            $this->totalsMigration($s)->up();
            self::fail('A conflicting known total was silently repaired.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows('sis_account_balances'));
            self::assertSame($events, $this->journal($s));
        }
    }

    public function testFinancialBreakdownsPreserveAccountAndCurrencyGrain(): void
    {
        $s = new SisFixture();
        foreach ([['USD', 10000, 15000, 2000], ['USD', 5000, 1000, 0], ['JPY', 2000, 1000, 0]] as $values) {
            [$currency, $charge, $payment, $refund] = $values;
            $account = $this->account($s, $currency, 0);
            $account->post(EntityId::generate(), EntryType::Charge, new Money($charge, $currency), $s->access->actor);
            $account->post(EntityId::generate(), EntryType::Payment, new Money($payment, $currency), $s->access->actor);
            if ($refund > 0) {
                $account->post(
                    EntityId::generate(),
                    EntryType::Refund,
                    new Money($refund, $currency),
                    $s->access->actor
                );
            }
            $s->saveAccount($account);
        }
        $q = $this->definition();
        $q['columns'] = [['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none']];
        foreach (['charges', 'payments', 'refunds', 'balance'] as $field) {
            $q['columns'][] = ['field' => $field, 'label' => $field, 'aggregate' => 'sum'];
        }
        $before = $this->journal($s);
        self::assertSame([
            ['c0' => 'JPY', 'c1' => 2000.0, 'c2' => 1000.0, 'c3' => 0.0, 'c4' => 1000.0],
            ['c0' => 'USD', 'c1' => 150.0, 'c2' => 160.0, 'c3' => 20.0, 'c4' => 10.0],
        ], $this->service($s)->preview($q, 0)['items']);
        self::assertSame($before, $this->journal($s));
        $q['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c2', 'operator' => 'greater', 'value' => 1200],
        ]];
        self::assertSame([], $this->service($s)->preview($q, 0)['items']);
        foreach (['charges', 'payments', 'refunds'] as $field) {
            $invalid = $this->definition();
            $invalid['columns'][4]['field'] = $field;
            unset($invalid['columns'][3]);
            $invalid['columns'] = array_values($invalid['columns']);
            $this->invalid($s, $invalid);
        }
    }

    public function testFixedBreakdownsRetainTheirValuesAfterLaterReversals(): void
    {
        $s = new SisFixture();
        $account = $this->account($s, minor: 1234);
        $q = $this->definition();
        foreach (['charges', 'payments', 'refunds'] as $field) {
            $q['columns'][] = ['field' => $field, 'label' => $field, 'aggregate' => 'none'];
        }
        $id = EntityId::generate();
        $service = $this->service($s);
        $service->save($id, $id, -1, 'Fictional financial breakdown', $q);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $captured = $service->run($id, 0)['items'];
        $account->reverse(new EntityId($account->entries()[0]['operation_id']), $s->access->actor);
        $s->saveAccount($account);
        self::assertSame($captured, $service->run($id, 0)['items']);
        self::assertSame(12.34, $captured[0]['c5']);
        self::assertSame(0.0, $service->preview($q, 0)['items'][0]['c5']);
    }

    public function testDetailReportsKeepCurrencyPrecisionSignsAndReadOnlyHistory(): void
    {
        $s = new SisFixture();
        foreach (['USD' => 1234, 'JPY' => -2000, 'BHD' => 1234, 'ZZZ' => 1234] as $currency => $minor) {
            $this->account($s, $currency, $minor);
        }
        $before = $this->journal($s);
        $rows = $this->service($s)->preview($this->definition(), 0)['items'];
        $values = array_column($rows, 'c4', 'c3');
        self::assertSame(12.34, $values['USD']);
        self::assertSame(-2000.0, $values['JPY']);
        self::assertSame(1.234, $values['BHD']);
        self::assertNull($values['ZZZ']);
        self::assertSame($before, $this->journal($s));
    }

    public function testSummariesSeparateCurrenciesAndCannotMultiplyBalancesThroughRelatedRows(): void
    {
        $s = new SisFixture();
        $a = $this->account($s, 'USD', 1200);
        $this->account($s, 'USD', -200);
        $this->account($s, 'JPY', 1200);
        $q = $this->definition();
        $q['columns'] = [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance', 'label' => 'Net balance', 'aggregate' => 'sum'],
        ];
        $rows = $this->service($s)->preview($q, 0)['items'];
        self::assertSame(['JPY' => 1200.0, 'USD' => 10.0], array_column($rows, 'c1', 'c0'));
        $invalid = $q;
        array_shift($invalid['columns']);
        $this->invalid($s, $invalid);
        $invalid = $q;
        $invalid['joins'] = ['registration' => 'left'];
        $this->invalid($s, $invalid);
        $invalid = $q;
        $invalid['join_filters'] = ['registration' => null];
        $this->invalid($s, $invalid);
        $section = $s->section(20, 0);
        $s->service->register($a->studentId(), $section->id, true);
        $second = $s->section(20, 0);
        $s->service->register($a->studentId(), $second->id, true);
        $q['existence'] = ['registration' => ['mode' => 'any', 'filters' => null]];
        self::assertSame([['c0' => 'USD', 'c1' => 12.0]], $this->service($s)->preview($q, 0)['items']);
    }

    private function invalid(SisFixture $s, array $definition): void
    {
        try {
            $this->service($s)->preview($definition, 0);
            self::fail('An unsafe monetary report was accepted.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testCurrencyPrecisionAlsoAppliesBeforeSummaryComparisons(): void
    {
        $s = new SisFixture();
        $this->account($s, minor: 10);
        $this->account($s, minor: 20);
        $q = $this->definition();
        $q['columns'] = [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'balance', 'label' => 'Balance', 'aggregate' => 'sum'],
        ];
        $q['having'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'c1', 'operator' => 'equal', 'value' => 0.3],
        ]];
        self::assertSame([['c0' => 'USD', 'c1' => 0.3]], $this->service($s)->preview($q, 0)['items']);
    }

    public function testMoneyCalculationsRequireMatchingCurrencyAndUnits(): void
    {
        $s = new SisFixture();
        foreach (['balance_minor', 'account_id'] as $other) {
            $q = $this->definition();
            $q['columns'][4]['calculation'] = ['operator' => '+', 'field' => $other];
            $this->invalid($s, $q);
        }
        $q = $this->definition();
        $q['columns'][4]['calculation'] = ['operator' => '*', 'field' => 'balance'];
        $this->invalid($s, $q);
    }

    public function testSharingAndStaticCaptureKeepFinancialAndStudentPermissionsIndependent(): void
    {
        $s = new SisFixture();
        $account = $this->account($s, minor: 1234);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional balances', $this->definition());
        $before = $this->journal($s);
        $live = $service->run($id, 0)['items'];
        $settings = $service->configureReport(
            $id,
            EntityId::generate(),
            $service->reportSettings($id)['version'],
            'static',
            null
        );
        self::assertSame($live, $service->run($id, 0)['items']);
        self::assertSame($before, $this->journal($s));
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        foreach ([['sis:student:read'], ['sis:finance:read']] as $grants) {
            $s->access->grants = ['sis:reporting:run', ...$grants];
            try {
                $service->run($id, 0);
                self::fail('A shared financial capture granted additional data permissions.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $s->access->grants = ['sis:reporting:run', 'sis:finance:read', 'sis:student:read'];
        self::assertSame($live, $service->run($id, 0)['items']);
        self::assertSame('static', $settings['lifecycle']['mode']);
        $s->access->staff = true;
        $account->post(EntityId::generate(), EntryType::Payment, new Money(34, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        self::assertSame($live, $service->run($id, 0)['items']);
        self::assertSame(12.0, $service->preview($this->definition(), 0)['items'][0]['c4']);
    }

    public function testFinancialSourceIsAbsentFromTheCatalogWithoutBothDataGrants(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        foreach ([[], ['sis:finance:read'], ['sis:student:read']] as $grants) {
            $s->access->grants = ['sis:reporting:manage', ...$grants];
            self::assertNotContains('accounts', array_column($this->service($s)->catalog()['datasets'], 'id'));
        }
        $s->access->grants = ['sis:reporting:manage', 'sis:finance:read', 'sis:student:read'];
        self::assertContains('accounts', array_column($this->service($s)->catalog()['datasets'], 'id'));
    }

    public function testBalanceSchemaAndMoneyReportsCompileWithAllFiveFrameworkDialects(): void
    {
        $s = new SisFixture();
        $table = null;
        $schema = $this->createMock(\Qubus\Expressive\Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects(self::once())->method('create')->willReturnCallback(
            static function (string $name, callable $callback) use (&$table): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $callback($table);
            }
        );
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($s->connection->queryBuilder());
        $migration = new \CreateSisAccountBalances('account-balances');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migrations'),
        ]));
        $migration->up();
        $typed = new ReportQuery($this->definition(), AcademicReportSchema::datasets()['accounts']);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $ddl = json_encode(new $compiler($dialect)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sis_account_balance_fk', $ddl, $driver);
            self::assertStringContainsString('charges_minor', $ddl, $driver);
            self::assertStringContainsString('payments_minor', $ddl, $driver);
            self::assertStringContainsString('refunds_minor', $ddl, $driver);
            self::assertStringNotContainsString('AUTO_INCREMENT', strtoupper($ddl));
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($dialect);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile($typed);
            self::assertStringContainsString('sis_account_balances', $plan['sql']);
            self::assertStringContainsString("'JPY'", $plan['sql']);
            self::assertSame([], $plan['parameters']);
        }
    }

    public function testTotalsUpgradeColumnsCompileWithAllFiveFrameworkDialects(): void
    {
        $s = new SisFixture();
        $changes = [];
        $schema = $this->createMock(\Qubus\Expressive\Schema::class);
        $schema->method('getColumns')->willReturn(['id', 'balance_minor', 'position']);
        $schema->expects(self::exactly(3))->method('alter')->willReturnCallback(
            static function (string $name, callable $callback) use (&$changes): void {
                $table = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($table);
                $changes[] = $table;
            }
        );
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($s->connection->queryBuilder());
        $migration = new \AddSisAccountTotals('account-totals');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migrations'),
        ]));
        $migration->up();
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $class($dialect);
            $statements = [];
            foreach ($changes as $change) {
                array_push($statements, ...$compiler->alter($change));
            }
            $ddl = json_encode($statements, JSON_THROW_ON_ERROR);
            foreach (['charges_minor', 'payments_minor', 'refunds_minor'] as $column) {
                self::assertStringContainsString($column, $ddl, $driver);
            }
            self::assertStringContainsString($driver === 'Oracle' ? 'NUMBER(19)'
                : ($driver === 'SQLite' ? 'INTEGER' : 'BIGINT'), $ddl, $driver);
            self::assertStringNotContainsString('DEFAULT 0', $ddl);
        }
    }

    public function testPreviewRechecksFinancialAccessAfterReadingTheSource(): void
    {
        $s = new SisFixture();
        $this->account($s);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:finance:read', 'sis:student:read'];
        $source = $this->createStub(\Domain\Reporting\Repository\RelationalReportSource::class);
        $source->method('datasets')->willReturn(AcademicReportSchema::datasets());
        $source->method('execute')->willReturnCallback(
            static function (ReportQuery $query, int $offset) use ($s): array {
                $rows = new SqlRelationalReportSource($s->sql)->execute($query, $offset);
                $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
                return $rows;
            }
        );
        $service = new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            $source,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
        $this->expectException(AccessDeniedException::class);
        $service->preview($this->definition(), 0);
    }
}
