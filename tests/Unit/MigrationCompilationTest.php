<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

require_once dirname(__DIR__, 2) . '/database/migrations/20261106000000_CreateSisAdmissionsTables.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000001_CreateSisStudentAccountsTable.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000002_CreateSisWorkflowTables.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000003_CreateSisBillingRulesTable.php';
require_once dirname(__DIR__, 2) . '/database/migrations/20261106000004_CreateSisPersonIdentifiersTable.php';

require_once dirname(__DIR__, 2) . '/database/migrations/20261106000005_CreateSisAccessTables.php';

require_once dirname(__DIR__, 2) . '/database/migrations/20261106000006_CreateSisWorkspaceTables.php';

require_once dirname(__DIR__, 2) . '/database/migrations/20261107000000_CreateCampusPortalTables.php';

final class MigrationCompilationTest extends TestCase
{
    public function testNewTablesCompileWithEveryInstalledSchemaCompiler(): void
    {
        $tables = [];
        $live = new \Qubus\Expressive\Connection\Pdo\Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $live->pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY, role VARCHAR(80), first_name VARCHAR(191), last_name VARCHAR(191))');
        $schema = $this->createMock(Schema::class);
        $schema->expects(self::exactly(53))->method('create')->willReturnCallback(
            static function (string $name, callable $callback) use (&$tables, $live): void {
                $table = new CreateTable($name);
                $callback($table);
                $tables[] = $table;
                $live->getSchema()->create($name, $callback);
            },
        );
        $connection = $this->createStub(Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($live->queryBuilder());
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name) => '"' . $name . '"');
        $adapter = new DbalMigrationAdapter($connection, 'test_migrations');
        foreach ([new CreateSisAdmissionsTables('1'), new CreateSisStudentAccountsTable('2'), new CreateSisWorkflowTables('3'), new CreateSisBillingRulesTable('4'), new CreateSisPersonIdentifiersTable('5'), new CreateSisAccessTables('6'), new CreateSisWorkspaceTables('7'), new CreateCampusPortalTables('8')] as $migration) {
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' => $adapter]));
            $migration->up();
        }
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => match ($driver) {
                'MySQL' => '`' . $name . '`',
                'SQLServer' => '[' . $name . ']',
                default => '"' . $name . '"',
            });
            $compiler = new $class($dialect);
            foreach ($tables as $table) {
                $commands = $compiler->create($table);
                self::assertNotEmpty($commands, $driver . ' must compile ' . $table->getTableName());
                self::assertSame('string', $table->getColumns()['id']->getType());
                self::assertSame(26, $table->getColumns()['id']->get('length'));
            }
        }
    }
}
