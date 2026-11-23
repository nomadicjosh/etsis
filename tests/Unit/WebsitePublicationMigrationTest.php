<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class WebsitePublicationMigrationTest extends TestCase
{
    public function testFourTablesCompileForFiveDialectsWithoutInferringApprovalsOrGrants(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000004_CreateSisWebsitePublications.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $native = new Sqlite(['dsn' => 'sqlite::memory:', 'driver' => 'pdo_sqlite']);
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('hasTable')->willReturnCallback($native->getSchema()->hasTable(...));
            $schema->method('create')->willReturnCallback(static function (
                string $name,
                callable $callback
            ) use (
                &$definitions,
                $native,
            ): void {
                $table = new CreateTable($name);
                $callback($table);
                $definitions[] = $table;
                $native->getSchema()->create($name, $callback);
            });
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('queryBuilder')->willReturn($native->queryBuilder());
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn (string $name): string => '"' . $name . '"',
            );
            $migration = new \CreateSisWebsitePublications('website');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $migration->up();
            self::assertCount(4, $definitions);
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(array_map(new $class($connection)->create(...), $definitions), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sis_navigation_menus', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |ALTER |DELETE |sis_history_|sis_access_/i', $sql);
            self::assertSame(3, (int) $native->pdo->query('SELECT COUNT(*) FROM sis_navigation_menus')->fetchColumn());
            self::assertSame(0, (int) $native->pdo->query('SELECT COUNT(*) FROM sis_page_publications')->fetchColumn());
            self::assertSame(0, (int) $native->pdo->query(
                'SELECT COUNT(*) FROM sis_navigation_menus WHERE published_items IS NOT NULL',
            )->fetchColumn());
        }
    }
}
