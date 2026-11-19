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

final class FinancialPostingReportTest extends TestCase
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
            $s->reportLifecycles(),
        );
    }

    private function account(SisFixture $s, string $currency = 'USD'): StudentAccount
    {
        return StudentAccount::open(new AccountId(), $s->student()->id, $s->term, $currency);
    }

    private function definition(string $dataset = 'postings'): array
    {
        return ['dataset' => $dataset, 'columns' => [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'posting_amount', 'label' => 'Amount', 'aggregate' => 'sum'],
        ], 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'posting_reversed', 'operator' => 'equal', 'value' => false],
        ]]];
    }

    private function journal(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations']);
    }

    public function testPostingReportsHaveCorrectGrainAcrossCurrenciesAndAccountJoins(): void
    {
        $s = new SisFixture();
        // A billing operation may legitimately be used once in several different accounts.
        $operation = EntityId::generate();
        foreach (['USD', 'JPY'] as $currency) {
            $account = $this->account($s, $currency);
            $account->post($operation, EntryType::Charge, new Money(10000, $currency), $s->access->actor);
            $account->post(
                EntityId::generate(),
                EntryType::Payment,
                new Money(2500, $currency),
                $s->access->actor,
                staffRecorded: true
            );
            $s->saveAccount($account);
        }
        self::assertCount(4, $s->sql->rows('sis_account_entries'));
        $d = $this->definition();
        $d['filters']['rules'][] = ['id' => 'posting_type', 'operator' => 'equal', 'value' => 'payment'];
        $before = $this->journal($s);
        foreach (['postings', 'accounts'] as $source) {
            $d['dataset'] = $source;
            $rows = array_column($this->service($s)->preview($d, 0)['items'], null, 'c0');
            self::assertSame(25.0, $rows['USD']['c1']);
            self::assertSame(2500.0, $rows['JPY']['c1']);
        }
        self::assertSame($before, $this->journal($s));
        self::assertArrayNotHasKey('balance', AcademicReportSchema::datasets()['postings']['fields']);
        $d['columns'][1]['calculation'] = ['operator' => '+', 'field' => 'balance'];
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testCorrectionsAndCoupledReversalsRetainOriginalPostingsAndCurrentNetEffects(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $charge = EntityId::generate();
        $account->post($charge, EntryType::Charge, new Money(10000, 'USD'), $s->access->actor, staffRecorded: true);
        $s->saveAccount($account);
        $replacement = EntityId::generate();
        $account->correct($charge, $replacement, new Money(12000, 'USD'), $s->access->actor, str_repeat('a', 64), null);
        $s->saveAccount($account);
        $credit = EntityId::generate();
        $account->creditCharge($replacement, $credit, new Money(2000, 'USD'), $s->access->actor, str_repeat('b', 64));
        $s->saveAccount($account);
        $rows = array_column($s->sql->rows('sis_account_entries'), null, 'operation_id');
        self::assertSame(10000, (int) $rows[$charge->value]['amount_minor']);
        self::assertTrue((bool) $rows[$charge->value]['reversed']);
        self::assertSame($replacement->value, $rows[$charge->value]['corrected_by_operation_id']);
        self::assertSame($charge->value, $rows[$replacement->value]['corrects_operation_id']);
        self::assertSame($replacement->value, $rows[$credit->value]['source_operation_id']);
        self::assertSame('staff_correction', $rows[$replacement->value]['origin']);
        $d = $this->definition();
        $d['columns'][1]['field'] = 'posting_effect_minor';
        self::assertSame(10000, $this->service($s)->preview($d, 0)['items'][0]['c1']);
        $account->reverse($replacement, $s->access->actor);
        $s->saveAccount($account);
        self::assertCount(3, $s->sql->rows('sis_account_entries'));
        self::assertSame([], $this->service($s)->preview($d, 0)['items']);
        $head = $s->sql->one('sis_account_entry_heads', ['id' => (string) $account->aggregateId()]);
        self::assertSame($account->playhead(), (int) $head['position']);
        self::assertSame(3, (int) $head['entry_count']);
    }

    public function testLatestPaymentsAreScopedBeforeSelectionAndSafeTotalsRejectOtherManyJoins(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        foreach ([1000, 2000] as $minor) {
            $account->post(EntityId::generate(), EntryType::Payment, new Money($minor, 'USD'), $s->access->actor);
        }
        $account->post(EntityId::generate(), EntryType::Charge, new Money(5000, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $d = $this->definition('accounts');
        unset($d['filters']);
        $d['record_selection'] = ['entry' => [
            'date_field' => 'posting_recorded_at', 'direction' => 'latest', 'ties' => 'one',
        ]];
        $d['join_filters'] = ['entry' => ['condition' => 'AND', 'rules' => [
            ['id' => 'posting_type', 'operator' => 'equal', 'value' => 'payment'],
            ['id' => 'posting_reversed', 'operator' => 'equal', 'value' => false],
        ]]];
        $value = $this->service($s)->preview($d, 0)['items'][0]['c1'];
        self::assertContains($value, [10.0, 20.0]);
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($d, AcademicReportSchema::datasets()['accounts']),
        );
        $steps = $s->sql->fetch('EXPLAIN QUERY PLAN ' . $plan['sql'], $plan['parameters']);
        self::assertNotEmpty(array_filter($steps, static fn(array $row): bool =>
            str_contains($row['detail'], 'SEARCH pick_row') && str_contains($row['detail'], 'account_id=?')));
        $d['joins'] = ['registration' => 'left'];
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testPostingProjectionFailureRollsBackJournalBalanceAndHeadTogether(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $account->post(EntityId::generate(), EntryType::Charge, new Money(1000, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $s->sql->update('sis_account_entry_heads', ['entry_count' => 99], ['id' => (string) $account->aggregateId()]);
        $before = $this->journal($s);
        $balances = $s->sql->rows('sis_account_balances');
        $rows = $s->sql->rows('sis_account_entries');
        $account->post(EntityId::generate(), EntryType::Payment, new Money(200, 'USD'), $s->access->actor);
        try {
            $s->saveAccount($account);
            self::fail('A corrupt posting checkpoint committed a new financial decision.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->journal($s));
            self::assertSame($balances, $s->sql->rows('sis_account_balances'));
            self::assertSame($rows, $s->sql->rows('sis_account_entries'));
        }
    }

    public function testStaticPostingCapturesStayOriginalAndSharingDoesNotGrantFinanceAccess(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $payment = EntityId::generate();
        $account->post($payment, EntryType::Payment, new Money(2500, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional posting summary', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $account->reverse($payment, $s->access->actor);
        $s->saveAccount($account);
        self::assertSame([], $service->preview($this->definition(), 0)['items']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', 'sis:finance:read'];
        self::assertEquals(25.0, $service->run($id, 0)['items'][0]['c1']);
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testMissingCheckpointStopsLiveReportsAndNoPrivatePayloadsBecomeReportFields(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $s->saveAccount($account);
        $fields = AcademicReportSchema::datasets()['postings']['fields'];
        foreach ($fields as $field) {
            self::assertNotContains($field['column'], [
                'sealed_event', 'encrypted_content', 'reference', 'reason', 'actor_id', 'owner_user_id',
            ]);
        }
        $s->sql->update('sis_account_entry_heads', ['position' => 0], ['id' => (string) $account->aggregateId()]);
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($this->definition(), 0);
    }

    public function testNamedPostingCopiesKeepTheirGrainAndRejectIndependentFactMultiplication(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $account->post(EntityId::generate(), EntryType::Charge, new Money(10000, 'USD'), $s->access->actor);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(2500, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $d = ['dataset' => 'accounts', 'aliases' => [
            'copy1' => ['relation' => 'entry', 'label' => 'Payment postings'],
        ], 'columns' => [
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'copy1__posting_amount', 'label' => 'Payments', 'aggregate' => 'sum'],
        ], 'join_filters' => ['copy1' => ['condition' => 'AND', 'rules' => [
            ['id' => 'copy1__posting_type', 'operator' => 'equal', 'value' => 'payment'],
        ]]]];
        self::assertSame([['c0' => 'USD', 'c1' => 25.0]], $this->service($s)->preview($d, 0)['items']);
        $d['columns'][] = ['field' => 'posting_amount', 'label' => 'Other postings', 'aggregate' => 'sum'];
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testPostingDetailsSupplyDistinctCurrentStudentsWithoutTriggeringFinancialActions(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        foreach ([100, 200] as $minor) {
            $account->post(EntityId::generate(), EntryType::Payment, new Money($minor, 'USD'), $s->access->actor);
        }
        $s->saveAccount($account);
        $d = $this->definition();
        $d['columns'][1]['aggregate'] = 'none';
        $id = EntityId::generate();
        $service = $this->service($s);
        $service->save($id, $id, -1, 'Fictional payment shortlist', $d);
        $before = $this->journal($s);
        $population = $service->population($id, 0, \Domain\Reporting\Enum\StudentPopulationAction::Billing);
        self::assertSame([$account->studentId()->value], array_column($population['items'], 'id'));
        self::assertSame($before, $this->journal($s));
        foreach ($account->entries() as $entry) {
            $account->reverse(new EntityId($entry['operation_id']), $s->access->actor);
        }
        $s->saveAccount($account);
        self::assertSame([], $service->population(
            $id,
            0,
            \Domain\Reporting\Enum\StudentPopulationAction::Billing,
        )['items']);
    }

    public function testConversionFailureRollsBackAllAccountsWithoutChangingProtectedEvents(): void
    {
        $s = new SisFixture();
        $a = $this->account($s);
        $b = $this->account($s);
        foreach ([$a, $b] as $account) {
            $account->post(EntityId::generate(), EntryType::Payment, new Money(200, 'USD'), $s->access->actor);
            $s->saveAccount($account);
        }
        $s->sql->execute('DELETE FROM sis_account_entries');
        $s->sql->execute('DELETE FROM sis_account_entry_heads');
        $s->sql->update('sis_student_accounts', ['currency' => 'EUR'], ['id' => (string) $b->aggregateId()]);
        $before = $this->journal($s);
        try {
            new FinancialProjectionUpgrade($s->connection->queryBuilder(), new ProtectedEventCodec(
                \EventStoreFixture::config($s->connection->queryBuilder()),
            ))->run('student_account', entries: true);
            self::fail('A conflicting account allowed partial projection conversion.');
        } catch (CorruptHistoryException) {
            self::assertSame([], $s->sql->rows('sis_account_entries'));
            self::assertSame([], $s->sql->rows('sis_account_entry_heads'));
            self::assertSame($before, $this->journal($s));
        }
    }

    public function testMigrationReconstructsProtectedEventsWithoutChangingAnyJournalOrOriginalReceipt(): void
    {
        $s = new SisFixture();
        $account = $this->account($s);
        $payment = EntityId::generate();
        $account->post($payment, EntryType::Payment, new Money(1200, 'USD'), $s->access->actor, staffRecorded: true);
        $s->saveAccount($account);
        $account->reverse($payment, $s->access->actor);
        $s->saveAccount($account);
        $before = $this->journal($s);
        $rows = $s->sql->rows('sis_account_entries');
        $s->connection->pdo->exec('DROP TABLE sis_account_entry_heads');
        $s->connection->pdo->exec('DROP TABLE sis_account_entries');
        $migration = new \CreateSisAccountEntryReports('posting-reports');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'test_migrations',
            ),
            'sis.history_codec' => new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
        ]));
        $migration->up();
        $restored = $s->sql->rows('sis_account_entries');
        self::assertSame($rows, $restored);
        foreach (['type', 'amount_minor', 'effect_minor', 'currency', 'reversed', 'recorded_at', 'origin'] as $field) {
            self::assertSame($rows[0][$field], $restored[0][$field]);
        }
        $migration->up();
        self::assertSame($restored, $s->sql->rows('sis_account_entries'));
        self::assertSame($before, $this->journal($s));
        $s->sql->update('sis_account_entries', ['amount_minor' => 99], ['id' => $restored[0]['id']]);
        $this->expectException(CorruptHistoryException::class);
        $migration->up();
    }

    public function testProjectionTablesCompileInEveryFrameworkDialectWithoutAnotherEventTable(): void
    {
        $s = new SisFixture();
        $tables = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->method('create')->willReturnCallback(
            static function (string $name, callable $definition) use (&$tables): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $definition($table);
                $tables[] = $table;
            },
        );
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($s->connection->queryBuilder());
        $quote = static fn(string $name): string => '"' . $name . '"';
        $connection->method('quoteIdentifier')->willReturnCallback($quote);
        $migration = new \CreateSisAccountEntryReports('posting-schema');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        self::assertCount(2, $tables);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $type = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $type($connection);
            $sql = json_encode(array_map($compiler->create(...), $tables), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sae_account_operation', $sql);
            self::assertStringContainsString('sae_account_date', $sql);
            self::assertStringContainsString('sis_account_entry_heads', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
            self::assertStringNotContainsString('sealed_event', $sql);
        }
    }
}
