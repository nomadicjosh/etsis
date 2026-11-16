<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class BillingBatchMigrationTest extends TestCase
{
    public function testCompletedReceiptTableCompilesWithoutChangingExistingFinancialFacts(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000005_CreateSisBillingBatches.php';
        $definitions = [];
        $schema = $this->createStub(Schema::class);
        $schema->method('create')->willReturnCallback(static function (
            string $name,
            callable $callback
        ) use (
            &$definitions,
        ): void {
            $table = new CreateTable($name);
            $callback($table);
            $definitions[] = $table;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $s): string => '"' . $s . '"');
        $migration = new \CreateSisBillingBatches('20261116000005');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        self::assertCount(1, $definitions);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $class($connection)->create($definitions[0]), JSON_THROW_ON_ERROR);
            foreach (
                ['sis_billing_batches', 'PRIMARY KEY', 'actor_id', 'term_id', 'request_hash', 'encrypted_result',
                'completed_at', 'REFERENCES', 'users', 'sis_terms'] as $part
            ) {
                self::assertStringContainsString($part, $sql, $dialect);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
