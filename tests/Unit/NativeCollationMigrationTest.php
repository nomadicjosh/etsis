<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class NativeCollationMigrationTest extends TestCase
{
    public static function definitions(): array
    {
        return [
            ['20261124000004', 'CreateSisAdmissionChangeRequests', 'sis_admission_change_requests', 2],
            ['20261124000007', 'CreateSisAdvisingUpdates', 'sis_advising_updates', 4],
            ['20261124000008', 'CreateSisAccountProjectionRepairs', 'sis_account_projection_repairs', 1],
        ];
    }

    #[DataProvider('definitions')]
    public function testPortableMigrationsUseOrdinaryDefinitionsWithoutInspectingDriverOrParentCollations(
        string $version,
        string $class,
        string $name,
        int $foreignKeys,
    ): void {
        require_once dirname(__DIR__, 2) . '/database/migrations/' . $version . '_' . $class . '.php';
        $definition = null;
        $schema = $this->createMock(Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects(self::once())->method('create')->with($name, self::isCallable())->willReturnCallback(
            static function (string $table, callable $define) use (&$definition): void {
                $definition = new CreateTable($table);
                $define($definition);
            }
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getDsn');
        $connection->expects(self::never())->method('command');
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        $migration = new $class($version);
        $adapter = new DbalMigrationAdapter($connection, 'migration');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' => $adapter]));
        $migration->up();
        self::assertInstanceOf(CreateTable::class, $definition);
        $commands = new \Qubus\Expressive\Schema\Compiler\PostgreSQL($connection)->create($definition);
        $sql = implode("\n", array_column($commands, 'sql'));
        self::assertStringNotContainsString('COLLATE', $sql);
        self::assertStringNotContainsString('information_schema', $sql);
        self::assertSame($foreignKeys, substr_count($sql, 'FOREIGN KEY'));
        foreach ($commands as $command) {
            self::assertIsArray($command['params']);
        }
    }
}
