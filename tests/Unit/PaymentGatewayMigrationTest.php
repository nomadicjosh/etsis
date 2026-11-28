<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class PaymentGatewayMigrationTest extends TestCase
{
    public function testPortableSetupTablesAndRepeatPreserveRecordedFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
        $g = new \Tests\Support\PaymentGatewayFixture();
        $f = $g->sis;
        $before = [];
        foreach (
            ['sis_history_events', 'sis_payment_connections', 'sis_payment_routes', 'sis_payment_reviews',
            'sis_ledger_accounts', 'sis_portal_payments', 'sis_access_permissions', 'sis_access_user_permissions',
            'sis_access_implications'] as $table
        ) {
            $before[$table] = $f->sql->rows($table);
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            foreach (['connections', 'routes', 'reviews'] as $kind) {
                $table = new CreateTable('sis_payment_' . $kind);
                \CreateSisPaymentGateways::$kind($table);
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString('PRIMARY KEY', strtoupper($sql));
                self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|AUTO_INCREMENT|COLLATE/i', $sql);
            }
        }
        $migration = new \CreateSisPaymentGateways('20261127000013');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $f->connection,
            'test_migrations'
        )]));
        $migration->up();
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
    }
    public function testUnfinishedUnboundCheckoutsRequireReconciliationInsteadOfInventedCredentials(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        $c = new \CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $f->service->register($student->id, $f->section(fee: 1000)->id);
        $account = new \Domain\Shared\ValueObject\EntityId($c->service->me()['accounts'][0]['id']);
        $c->checkout->checkout(\Domain\Shared\ValueObject\EntityId::generate(), $account);
        $f->connection->pdo->exec('ALTER TABLE sis_portal_payments DROP COLUMN sealed_gateway');
        $before = $f->sql->rows('sis_portal_payments');
        $migration = new \CreateSisPaymentGateways('20261127000013');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $f->connection,
            'test_migrations'
        )]));
        try {
            $migration->up();
            self::fail('An unfinished earlier checkout was silently assigned current credentials.');
        } catch (\LogicException) {
            self::assertSame($before, $f->sql->rows('sis_portal_payments'));
            self::assertNotContains('sealed_gateway', $f->connection->getSchema()->getColumns(
                'sis_portal_payments',
                clear: true
            ));
        }
    }
}
