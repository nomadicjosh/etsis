<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ChargeSourceWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function rules(SisFixture $s): TermBillingService
    {
        $db = $s->connection->queryBuilder();
        return new TermBillingService(new EventSourcedBillingRuleRepository(
            $s->sql,
            EventStoreFixture::framework($db),
            $s->transactions,
            EventStoreFixture::financialWriter($db)
        ), $s->access, $s->transactions, $s->audit, $s->financialCommands());
    }

    private function reports(SisFixture $s): RelationalReportService
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

    private function journal(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations']);
    }

    public function testReviewedTermBillingAndRegistrationRetainEachActualRuleKindAndDropBehavior(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $rules = $this->rules($s);
        foreach ([BillingBasis::Term, BillingBasis::RegisteredCredit] as $basis) {
            foreach (['tuition', 'fee'] as $kind) {
                $rules->define(
                    EntityId::generate(),
                    $s->term,
                    null,
                    'Fictional ' . $kind,
                    $kind,
                    new Money(1000, 'USD'),
                    'Fictional approved pricing.',
                    $basis
                );
            }
        }
        $batch = $s->bill($s->term, [$student->id]);
        $accountId = new AccountId($batch['students'][0]['account_id']);
        self::assertSame(
            ['term_tuition', 'term_fee'],
            array_column($s->accounts->get($accountId)->entries(), 'charge_source')
        );
        $section = $s->section(fee: 500);
        $s->service->register($student->id, $section->id);
        $root = $s->accounts->get($accountId);
        self::assertSame(
            ['term_tuition', 'term_fee', 'section_fee', 'credit_tuition', 'credit_fee'],
            array_column($root->entries(), 'charge_source')
        );
        self::assertSame(
            array_column($root->entries(), 'charge_source'),
            array_column($s->sql->rows('sis_account_entries'), 'charge_source')
        );
        self::assertSame(8500, $root->balance()->minor);
        $before = $this->journal($s);
        $s->service->register($student->id, $section->id);
        self::assertSame($before, $this->journal($s));
        $r = $s->drops->review($student->id, $section->id);
        $s->drops->commit(
            $student->id,
            $section->id,
            new EntityId($r['enrollment_id']),
            $r['version'],
            new EntityId($r['operation_id']),
            $r['revision'],
            $r['effective_on']
        );
        $root = $s->accounts->get($accountId);
        self::assertSame([false, false, true, true, true], array_column($root->entries(), 'reversed'));
        self::assertSame(
            ['term_tuition', 'term_fee', 'section_fee', 'credit_tuition', 'credit_fee'],
            array_column($root->entries(), 'charge_source')
        );
        self::assertSame(2000, $root->balance()->minor);
    }

    public function testCorrectionsCreditsAndReportsRetainSourceWithoutReclassifyingEarlierEntries(): void
    {
        $s = new SisFixture();
        $root = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $actor = $s->access->actor;
        $manual = EntityId::generate();
        $earlier = EntityId::generate();
        $replacement = EntityId::generate();
        $credit = EntityId::generate();
        $root->post($manual, EntryType::Charge, new Money(1000, 'USD'), $actor, staffRecorded: true);
        $root->post($earlier, EntryType::Charge, new Money(300, 'USD'), $actor);
        $s->saveAccount($root);
        $root->correct($manual, $replacement, new Money(1200, 'USD'), $actor, str_repeat('a', 64), null);
        $s->saveAccount($root);
        $root->creditCharge($replacement, $credit, new Money(200, 'USD'), $actor, str_repeat('b', 64));
        $s->saveAccount($root);
        $root = $s->accounts->get($root->aggregateId());
        self::assertSame('manual_charge', $root->entry($manual)['charge_source']);
        self::assertSame('manual_charge', $root->entry($replacement)['charge_source']);
        self::assertSame('manual_charge', $root->entry($credit)['charge_source']);
        self::assertSame('Staff-recorded charge', $root->entry($credit)['charge_source_label']);
        self::assertArrayNotHasKey('charge_source', $root->entry($earlier));
        $root->clearRecordedEvents();
        $root->post($earlier, EntryType::Charge, new Money(300, 'USD'), $actor, chargeSource: ChargeSource::TermFee);
        self::assertFalse($root->hasRecordedEvents());
        self::assertArrayNotHasKey('charge_source', $root->entry($earlier));
        $definition = ['dataset' => 'postings', 'columns' => [
            ['field' => 'posting_charge_source', 'label' => 'Source', 'aggregate' => 'none'],
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'posting_effect', 'label' => 'Net charges', 'aggregate' => 'sum'],
        ], 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'posting_reversed', 'operator' => 'equal', 'value' => false],
            ['id' => 'posting_type', 'operator' => 'in', 'value' => ['charge', 'charge_credit']],
        ]]];
        $before = $this->journal($s);
        $rows = array_column($this->reports($s)->preview($definition, 0)['items'], null, 'c0');
        self::assertSame(10.0, $rows['manual_charge']['c2']);
        self::assertSame(3.0, $rows['']['c2']);
        foreach ($rows as $row) {
            self::assertSame('USD', $row['c1']);
        }
        self::assertSame($before, $this->journal($s));
        $root->reverse($replacement, $actor);
        $s->saveAccount($root);
        self::assertSame(
            ['manual_charge', null, 'manual_charge', 'manual_charge'],
            array_column($s->sql->rows('sis_account_entries'), 'charge_source')
        );
        self::assertSame(300, $s->accounts->get($root->aggregateId())->balance()->minor);
    }

    public function testStaticAndSharedReportsKeepFinancialSourceAuthorization(): void
    {
        $s = new SisFixture();
        $root = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $operation = EntityId::generate();
        $root->post($operation, EntryType::Charge, new Money(1000, 'USD'), $s->access->actor, staffRecorded: true);
        $s->saveAccount($root);
        $definition = ['dataset' => 'postings', 'columns' => [
            ['field' => 'posting_charge_source', 'label' => 'Source', 'aggregate' => 'none'],
            ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
            ['field' => 'posting_effect', 'label' => 'Net charges', 'aggregate' => 'sum'],
        ], 'filters' => ['condition' => 'AND', 'rules' => [
            ['id' => 'posting_reversed', 'operator' => 'equal', 'value' => false],
        ]]];
        $reports = $this->reports($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, 'Fictional retained charge sources', $definition);
        $reports->configureReport($id, EntityId::generate(), 0, 'static', null);
        $root->reverse($operation, $s->access->actor);
        $s->saveAccount($root);
        self::assertSame([], $reports->preview($definition, 0)['items']);
        self::assertSame('manual_charge', $reports->run($id, 0)['items'][0]['c0']);
        self::assertEquals(10.0, $reports->run($id, 0)['items'][0]['c2']);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $reports->run($id, 0);
    }

    public function testTamperedSourceFailsTheNextFinancialDecisionAndRollsBackItsJournal(): void
    {
        $s = new SisFixture();
        $root = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $op = EntityId::generate();
        $root->post($op, EntryType::Charge, new Money(1000, 'USD'), $s->access->actor, staffRecorded: true);
        $s->saveAccount($root);
        $s->sql->update('sis_account_entries', ['charge_source' => 'term_fee'], ['operation_id' => $op->value]);
        $before = $this->journal($s);
        $balances = $s->sql->rows('sis_account_balances');
        $root->reverse($op, $s->access->actor);
        try {
            $s->saveAccount($root);
            self::fail('A conflicting source classification was silently repaired.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->journal($s));
            self::assertSame($balances, $s->sql->rows('sis_account_balances'));
        }
    }

    public function testPopulatedAdditiveMigrationPreservesUnknownEntriesAndIsIdempotent(): void
    {
        $s = new SisFixture();
        $root = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $root->post(EntityId::generate(), EntryType::Charge, new Money(1000, 'USD'), $s->access->actor);
        $s->saveAccount($root);
        $s->sql->execute('ALTER TABLE sis_account_entries DROP COLUMN charge_source');
        $before = $this->journal($s);
        $rows = $s->sql->rows('sis_account_entries');
        $balances = $s->sql->rows('sis_account_balances');
        $migration = new \AddSisChargeSourceReports('populated-charge-source');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'migration'
            ),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame($before, $this->journal($s));
        self::assertSame($balances, $s->sql->rows('sis_account_balances'));
        self::assertSame(
            array_map(static fn(array $row): array => $row + ['charge_source' => null], $rows),
            $s->sql->rows('sis_account_entries')
        );
        self::assertArrayNotHasKey('charge_source', $s->accounts->get($root->aggregateId())->entries()[0]);
    }

    public function testNullableChargeSourceMigrationCompilesAcrossFiveFrameworkDialects(): void
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
        $migration = new \AddSisChargeSourceReports("charge-source-projection");
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
            self::assertStringContainsString("charge_source", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression("/CREATE TABLE|DELETE FROM|DROP TABLE/i", $sql);
        }
    }
}
