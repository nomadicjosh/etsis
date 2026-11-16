<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Repository\AccessRepository;
use Domain\Finance\BillingRule;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Service\RegistrationBilling;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\WorkspaceRepository;
use Domain\Workspace\Service\WorkspaceService;
use EventStoreFixture;
use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use Infrastructure\Projection\SqlBillingRuleProjection;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class BillingRuleDirectoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function repository(SisFixture $s): EventSourcedBillingRuleRepository
    {
        $db = $s->connection->queryBuilder();
        return new EventSourcedBillingRuleRepository(
            $s->sql,
            EventStoreFixture::framework($db),
            $s->transactions,
            EventStoreFixture::financialWriter($db)
        );
    }

    private function service(SisFixture $s): TermBillingService
    {
        return new TermBillingService(
            $this->repository($s),
            $this->createStub(RegistrationBilling::class),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands()
        );
    }

    private function define(
        SisFixture $s,
        string $name = 'Fictional fee',
        string $currency = 'USD',
        ?EntityId $program = null
    ): BillingRule {
        return $this->service($s)->define(
            EntityId::generate(),
            $s->term,
            $program,
            $name,
            'fee',
            new Money(1234, $currency),
            'Private fictional setup reason.'
        );
    }

    private function directory(SisFixture $s): SqlWorkspaceRepository
    {
        return new SqlWorkspaceRepository($s->sql, $s->gradeCatalog(), $s->clock);
    }

    private function recorded(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_student_accounts', 'sis_account_balances', 'sis_audit']);
    }

    public function testDirectorySearchesLabelsAndStatusWithoutDecryptingEventsOrChangingHistory(): void
    {
        $s = new SisFixture();
        $fee = $this->define($s, 'Laboratory fee', program: $s->program);
        $old = $this->define($s, 'Earlier fee');
        $this->service($s)->retire($old->aggregateId(), 1, 'Retire an earlier fictional fee.');
        $directory = $this->directory($s);
        $before = $this->recorded($s);
        // An ordinary list does not require a history decryption key or a history port.
        EventStoreFixture::config($s->connection->queryBuilder())->setConfigKey('event_history', [
            'active_key_id' => 'unavailable', 'keys' => [],
        ]);
        foreach (['laboratory', 'BSCS', 'Computer Science', '2026FA', 'USD'] as $search) {
            $items = $directory->records('billing', null, $search, null)['items'];
            self::assertContains((string) $fee->aggregateId(), array_column($items, 'id'));
        }
        $active = $directory->records('billing', null, 'Active', null)['items'];
        self::assertSame([(string) $fee->aggregateId()], array_column($active, 'id'));
        self::assertSame('BSCS', $active[0]['program_label']);
        self::assertSame('12.34', $active[0]['amount_decimal']);
        self::assertSame('Active', $active[0]['status']);
        $retired = $directory->records('billing', null, 'Retired', null)['items'];
        self::assertSame([(string) $old->aggregateId()], array_column($retired, 'id'));
        self::assertSame('All programs', $retired[0]['program_label']);
        self::assertStringNotContainsString('Private fictional', json_encode($retired));
        self::assertSame($before, $this->recorded($s));
    }

    public function testResultsUseKeysetPagesAndFilterBeforeThePageBound(): void
    {
        $s = new SisFixture();
        $old = $this->define($s, 'Literal %_! fee');
        for ($i = 0; $i < 54; $i++) {
            $this->define($s, 'Current fee ' . $i);
        }
        $directory = $this->directory($s);
        $first = $directory->records('billing', null, '', null);
        $second = $directory->records('billing', null, '', new EntityId($first['next_after']));
        self::assertCount(50, $first['items']);
        self::assertCount(5, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(55, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        self::assertSame([(string) $old->aggregateId()], array_column(
            $directory->records('billing', null, '%_!', null)['items'],
            'id'
        ));
    }

    public function testAmountsRetainIsoPrecisionAndDoNotGuessUnknownCurrencyUnits(): void
    {
        $s = new SisFixture();
        foreach (['JPY' => '1234', 'KWD' => '1.234', 'USD' => '12.34', 'ZZZ' => null] as $currency => $decimal) {
            $this->define($s, 'Fee ' . $currency, $currency);
            $row = $this->directory($s)->records('billing', null, $currency, null)['items'][0];
            self::assertSame(1234, (int) $row['amount_minor']);
            self::assertSame($decimal, $row['amount_decimal']);
        }
    }

    public function testIncompleteMetadataStopsTheDirectoryWithoutReturningPartialResults(): void
    {
        foreach (['name', 'position', 'missing_row', 'missing_stream'] as $problem) {
            $s = new SisFixture();
            $fee = $this->define($s);
            $id = (string) $fee->aggregateId();
            if ($problem === 'missing_row') {
                $s->sql->execute('DELETE FROM sis_billing_rules WHERE id = ?', [$id]);
            } elseif ($problem === 'missing_stream') {
                $s->sql->execute('PRAGMA foreign_keys = OFF');
                $s->sql->execute('DELETE FROM sis_history_streams WHERE aggregate_id = ?', [$id]);
            } else {
                $s->sql->update('sis_billing_rules', [$problem => $problem === 'name' ? null : 99], ['id' => $id]);
            }
            $before = $this->recorded($s);
            try {
                $this->directory($s)->records('billing', null, 'No matches', null);
                self::fail('An inconsistent financial directory returned a partial or empty result.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->recorded($s));
            }
        }
    }

    public function testASelectedRuleAndRetirementRejectPlausibleButIncorrectProjectedValues(): void
    {
        $s = new SisFixture();
        $fee = $this->define($s);
        $s->sql->update('sis_billing_rules', ['amount_minor' => 1200], ['id' => (string) $fee->aggregateId()]);
        $before = $this->recorded($s);
        try {
            $this->service($s)->retire($fee->aggregateId(), 1, 'A projection conflict must be investigated.');
            self::fail('A financial decision silently repaired conflicting projected facts.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->recorded($s));
            self::assertSame(1200, (int) $s->sql->rows('sis_billing_rules')[0]['amount_minor']);
        }
    }

    public function testALaterCommittedRetirementRequiresARefreshOfAnEarlierRead(): void
    {
        $s = new SisFixture();
        $fee = $this->define($s);
        $earlier = $this->repository($s)->get($fee->aggregateId());
        $this->service($s)->retire($fee->aggregateId(), 1, 'A later approved retirement.');
        $this->expectException(ConcurrentModificationException::class);
        new SqlBillingRuleProjection($s->sql)->verify($earlier);
    }

    public function testAFailedProjectionRollsBackTheOpeningMetadataEventReceiptAndAudit(): void
    {
        $s = new SisFixture();
        $before = $this->recorded($s);
        $s->sql->execute("CREATE TRIGGER fictional_rule_projection_failure BEFORE UPDATE ON sis_billing_rules "
            . "BEGIN SELECT RAISE(ABORT, 'Fictional projection failure'); END");
        try {
            $this->define($s);
            self::fail('The event committed without its financial lookup projection.');
        } catch (\PDOException) {
            self::assertSame([], $s->sql->rows('sis_billing_rules'));
            self::assertSame($before, $this->recorded($s));
        }
    }

    public function testTheMigrationReconstructsOnlyRealEventsAndPreservesOriginalReceipts(): void
    {
        $s = new SisFixture();
        $s->sql->execute('DROP TABLE sis_billing_rules');
        $s->sql->execute('CREATE TABLE sis_billing_rules '
            . '(id VARCHAR(26) PRIMARY KEY, term_id VARCHAR(26), program_id VARCHAR(26))');
        foreach ([false, true] as $retired) {
            $root = BillingRule::define(
                new BillingRuleId(),
                $s->term,
                null,
                'Earlier fee',
                'fee',
                new Money(1234, 'USD'),
                $s->access->actor
            );
            if ($retired) {
                $root->retire($s->access->actor);
            }
            EventStoreFixture::framework($s->connection->queryBuilder())->commit(
                ...iterator_to_array($root->getRecordedEvents())
            );
            $s->sql->insert('sis_billing_rules', ['id' => (string) $root->aggregateId(),
                'term_id' => $s->term->value, 'program_id' => null]);
        }
        $before = $this->recorded($s);
        $migration = new \AddSisBillingRuleReadModel('billing-rule-lookup');
        $migration->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                'test_migrations'
            ),
            'sis.history_codec' => new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())),
        ]));
        $migration->up();
        $rows = $s->sql->rows('sis_billing_rules');
        self::assertSame([0, 1], array_map(static fn (array $r): int => (int) $r['retired'], $rows));
        self::assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['position'], $rows));
        $this->upgrade($s);
        self::assertSame($rows, $s->sql->rows('sis_billing_rules'));
        self::assertSame($before, $this->recorded($s));
    }

    private function upgrade(SisFixture $s): void
    {
        new FinancialProjectionUpgrade($s->connection->queryBuilder(), new ProtectedEventCodec(
            EventStoreFixture::config($s->connection->queryBuilder())
        ))->run('billing_rule');
    }

    public function testUnknownFieldsCanBeReconstructedButKnownConflictsRollBackTheWholeConversion(): void
    {
        $s = new SisFixture();
        $one = $this->define($s, 'First fee');
        $two = $this->define($s, 'Second fee');
        $s->sql->update(
            'sis_billing_rules',
            array_fill_keys(SqlBillingRuleProjection::FIELDS, null),
            ['id' => (string) $one->aggregateId()]
        );
        $s->sql->update(
            'sis_billing_rules',
            ['name' => 'Conflicting known name'],
            ['id' => (string) $two->aggregateId()]
        );
        $rows = $s->sql->rows('sis_billing_rules');
        $before = $this->recorded($s);
        try {
            $this->upgrade($s);
            self::fail('A conflicting known rule value was silently replaced.');
        } catch (CorruptHistoryException) {
            self::assertSame($rows, $s->sql->rows('sis_billing_rules'));
            self::assertSame($before, $this->recorded($s));
        }
    }

    public function testConversionStopsAtItsRecordBoundBeforeInventingMissingHistory(): void
    {
        $s = new SisFixture();
        for ($i = 0; $i < 201; $i++) {
            $s->sql->insert('sis_billing_rules', ['id' => EntityId::generate()->value,
                'term_id' => $s->term->value, 'program_id' => null]);
        }
        $this->expectException(\LogicException::class);
        new FinancialProjectionUpgrade($s->connection->queryBuilder())->run('billing_rule');
    }

    public function testDirectoryAuthorityIsRecheckedAfterTheRead(): void
    {
        foreach (['permission', 'actor'] as $change) {
            $s = new SisFixture();
            $repository = $this->createStub(WorkspaceRepository::class);
            $repository->method('records')->willReturnCallback(static function () use ($s, $change): array {
                if ($change === 'actor') {
                    $s->access->actor = $s->user();
                } else {
                    $s->access->staff = false;
                    $s->access->grants = ['admin:dashboard'];
                }
                return ['items' => [['name' => 'A private fictional fee']], 'next_after' => null];
            });
            $permissions = $this->createStub(AccessRepository::class);
            $service = new WorkspaceService($repository, $s->access, $permissions, $s->admissionsService());
            try {
                $service->records('billing');
                self::fail('A private directory survived a changed reader or revoked grant.');
            } catch (AccessDeniedException) {
                self::assertSame([], $s->sql->rows('sis_history_events'));
            }
        }
    }
}
