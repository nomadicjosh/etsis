<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class BookingCategoriesMigrationTest extends TestCase
{
    public function testAdditiveReferencesCompileAcrossTheFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000010_CreateSisBookingCategories.php';
        foreach (
            ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite',
            'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'] as $driver => $dialect
        ) {
            $definitions = [];
            $raw = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('create')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $schema->method('alter')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $connection->method('command')->willReturnCallback(static function (string $sql) use (&$raw): bool {
                $raw[] = $sql;
                return true;
            });
            $migration = new \CreateSisBookingCategories('booking-categories');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $class($connection);
            $commands = $raw;
            foreach ($definitions as $definition) {
                array_push($commands, ...($definition instanceof CreateTable
                    ? $compiler->create($definition) : $compiler->alter($definition)));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            foreach (
                ['sis_booking_categories', 'category_id', 'section_publication_id', 'request_hash',
                'sb_category_idx', 'sb_section_pub_idx', 'sis_section_versions', 'PRIMARY KEY', 'REFERENCES'] as $part
            ) {
                self::assertStringContainsString($part, $sql, $dialect);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO/i', $sql);
        }
    }

    public function testSqliteAddsReferencesWithoutRebuildingOrRewritingOldBookings(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000010_CreateSisBookingCategories.php';
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite(
            ['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']
        );
        $pdo = $connection->pdo;
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('CREATE TABLE sis_bookings (id VARCHAR(26) PRIMARY KEY, title VARCHAR(191), version INT)');
        $pdo->exec('CREATE TABLE sis_section_versions (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec("INSERT INTO sis_bookings VALUES ('00000000000000000000000001', 'Fictional earlier booking', 3)");
        $migration = new \CreateSisBookingCategories('booking-categories');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($connection, 'migration')]));
        $migration->up();
        $row = $pdo->query('SELECT * FROM sis_bookings')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Fictional earlier booking', $row['title']);
        self::assertSame(3, $row['version']);
        self::assertNull($row['category_id']);
        self::assertNull($row['section_publication_id']);
        self::assertNull($row['request_hash']);
        self::assertCount(2, $pdo->query('PRAGMA foreign_key_list(sis_bookings)')->fetchAll());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
