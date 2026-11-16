<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class RestrictionIndexMigrationTest extends TestCase
{
    public function testPersonPagingIndexCompilesWithoutChangingFactsInEveryFrameworkDialect(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000020_AddSisRestrictionPersonIndex.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('alter')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisRestrictionPersonIndex('restriction-index');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            self::assertCount(1, $definitions);
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sr_person_history', $sql);
            self::assertStringContainsString('person_id', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|CREATE TABLE/i', $sql);
            self::assertStringNotContainsString('sis_history', $sql);
        }
    }
}
