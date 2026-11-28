<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\PaymentGatewayFixture;

final class PaymentCaptureMigrationTest extends TestCase
{
    public function testPortableWorkTableAndRepeatPreservePaidHistory(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
        $g = new PaymentGatewayFixture();
        [$account, $checkout] = $g->charged();
        $checkout->checkout(EntityId::generate(), $account);
        self::assertSame(1, $g->campus->worker($g->gateway)->run()['done']);
        $f = $g->sis;
        $f->connection->getSchema()->drop('sis_payment_work');
        $before = [];
        foreach (['sis_history_events', 'sis_portal_payments', 'sis_account_entries'] as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $table = new CreateTable('sis_payment_work');
            \CreateSisPaymentWork::work($table);
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('PRIMARY KEY', strtoupper($sql));
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|AUTO_INCREMENT|COLLATE/i', $sql);
        }
        $migration = new \CreateSisPaymentWork('20261128000001');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $f->connection,
            'test_migrations'
        )]));
        $migration->up();
        $migration->up();
        self::assertSame([], $f->sql->rows('sis_payment_work'));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
    }

    public function testUnfinishedAutomaticSessionsRequireExplicitReconciliation(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
        $g = new PaymentGatewayFixture();
        [$account, $checkout] = $g->charged();
        $id = EntityId::generate();
        $checkout->checkout($id, $account);
        $f = $g->sis;
        $f->connection->getSchema()->drop('sis_payment_work');
        $migration = new \CreateSisPaymentWork('20261128000001');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter(
            $f->connection,
            'test_migrations'
        )]));
        foreach (['pending', 'expired'] as $status) {
            $f->sql->update('sis_portal_payments', ['status' => $status], ['id' => $id->value]);
            $before = $f->sql->rows('sis_portal_payments');
            try {
                $migration->up();
                self::fail('Unfinished automatic sessions were silently converted.');
            } catch (\LogicException) {
                self::assertFalse($f->connection->getSchema()->hasTable('sis_payment_work', clear: true));
                self::assertSame($before, $f->sql->rows('sis_portal_payments'));
            }
        }
    }
}
