<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use CreateSisBuildings;
use PDO;
use PDOException;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class BuildingMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000006_CreateSisBuildings.php';
    }

    public function testSchemaCompilesForTheFiveFrameworkDialects(): void
    {
        foreach (
            ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite',
            'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'] as $driver => $dialect
        ) {
            $statements = [];
            $pdo = $this->createMock(PDO::class);
            $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($driver);
            $pdo->expects($driver === 'sqlite' ? self::once() : self::never())->method('exec')
                ->willReturnCallback(static function (string $sql) use (&$statements): int {
                    $statements[] = $sql;
                    return 0;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $compiler($connection);
            $schema = $this->createMock(Schema::class);
            $schema->expects(self::once())->method('create')->willReturnCallback(
                static function (string $name, callable $define) use (&$statements, $compiler): void {
                    $table = new CreateTable($name);
                    $define($table);
                    array_push($statements, ...$compiler->create($table));
                }
            );
            $schema->expects(self::exactly($driver === 'sqlite' ? 2 : 3))->method('alter')->willReturnCallback(
                static function (string $name, callable $define) use (&$statements, $compiler): void {
                    $table = new AlterTable($name);
                    $define($table);
                    array_push($statements, ...$compiler->alter($table));
                }
            );
            $connection->method('getSchema')->willReturn($schema);
            $migration = new CreateSisBuildings('facilities');
            $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $sql = json_encode($statements, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sis_buildings', $sql, $dialect);
            self::assertStringContainsString('building_id', $sql, $dialect);
            self::assertStringContainsString('sr_building_idx', $sql, $dialect);
            self::assertStringContainsString('REFERENCES', $sql, $dialect);
            self::assertDoesNotMatchRegularExpression('/UPDATE |DELETE FROM|DROP TABLE/i', $sql, $dialect);
        }
    }

    public function testAddingMetadataPreservesEarlierRoomsAndDependentBookingsAndEnforcesTheForeignKey(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo = $connection->pdo;
        $pdo->exec('CREATE TABLE sis_rooms (id VARCHAR(26) PRIMARY KEY, code VARCHAR(80) UNIQUE, capacity INT)');
        $pdo->exec('CREATE TABLE sis_bookings (id VARCHAR(26) PRIMARY KEY, '
            . 'room_id VARCHAR(26) REFERENCES sis_rooms(id))');
        $pdo->exec("INSERT INTO sis_rooms VALUES ('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'EARLIER.1', 30)");
        $pdo->exec("INSERT INTO sis_bookings VALUES ('01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAV')");
        $before = $pdo->query('SELECT * FROM sis_rooms')->fetchAll(PDO::FETCH_ASSOC);
        $bookings = $pdo->query('SELECT * FROM sis_bookings')->fetchAll(PDO::FETCH_ASSOC);
        $migration = new CreateSisBuildings('facilities');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        self::assertSame($before, $pdo->query('SELECT id, code, capacity FROM sis_rooms')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($bookings, $pdo->query('SELECT * FROM sis_bookings')->fetchAll(PDO::FETCH_ASSOC));
        $row = $pdo->query('SELECT * FROM sis_rooms')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['name']);
        self::assertNull($row['building_id']);
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
        $this->expectException(PDOException::class);
        $pdo->exec("UPDATE sis_rooms SET building_id = '01ARZ3NDEKTSV4RRFFQ69G5FAX'");
    }
}
