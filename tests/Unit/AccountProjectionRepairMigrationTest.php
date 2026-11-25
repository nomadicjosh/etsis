<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AccountProjectionRepairMigrationTest extends TestCase
{
    public function testProtectedSupportReceiptsCompileAcrossFivePortableDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261124000008_CreateSisAccountProjectionRepairs.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_account_projection_repairs');
            \CreateSisAccountProjectionRepairs::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            $fields = ['account_id', 'sealed_result', 'completed_at', 'sapr_account_fk', 'sapr_account_time'];
            foreach ($fields as $field) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }



    public function testRepeatPreservesExistingRowsAndDestructiveDownIsRefused(): void
    {
        require_once dirname(__DIR__) . '/Support/AccountRecoveryFixture.php';
        $f = new \Tests\Support\AccountRecoveryFixture();
        try {
            $id = $f->stage();
            $f->service()->apply($id);
            $rows = $f->sis->sql->rows('sis_account_projection_repairs');
            $facts = $f->facts();
            $migration = new \CreateSisAccountProjectionRepairs('1');
            $adapter = new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $f->sis->connection,
                'test_migrations'
            );
            $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' => $adapter]));
            $migration->up();
            self::assertSame($rows, $f->sis->sql->rows('sis_account_projection_repairs'));
            self::assertSame($facts, $f->facts());
            $this->expectException(\LogicException::class);
            $migration->down();
        } finally {
            $f->close();
        }
    }
}
