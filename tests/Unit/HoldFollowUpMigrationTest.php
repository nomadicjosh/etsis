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

final class HoldFollowUpMigrationTest extends TestCase
{
    public function testOnlyAdditiveOfficeWorkDefinitionsCompileAcrossTheFiveFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000001_AddSisHoldFollowUp.php';
        $dialects = ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite',
            'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'];
        foreach ($dialects as $driver => $dialect) {
            $definitions = [];
            $raw = [];
            $schema = $this->createStub(Schema::class);
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
            $migration = new \AddSisHoldFollowUp('hold-follow-up');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $class($connection);
            $commands = $raw;
            foreach ($definitions as $definition) {
                array_push($commands, ...$compiler->alter($definition));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            $parts = ['sis_restriction_offices', 'assigned_user_id', 'follow_up_on', 'work_revision',
                'sro_assigned_work', 'sro_due_work', 'REFERENCES'];
            foreach ($parts as $part) {
                self::assertStringContainsString($part, $sql, $dialect);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|CREATE TABLE/i', $sql);
            self::assertStringNotContainsString('sis_history', $sql);
        }
    }

    public function testSqlitePreservesEarlierRoutingAndItsExistingForeignKeysWithoutInventingWork(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000001_AddSisHoldFollowUp.php';
        $connection = new \Infrastructure\Persistence\SqliteConnection(
            ['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']
        );
        $pdo = $connection->pdo;
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY)');
        $pdo->exec('CREATE TABLE sis_offices (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec('CREATE TABLE sis_restrictions (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec('CREATE TABLE sis_restriction_offices (id VARCHAR(26) PRIMARY KEY REFERENCES sis_restrictions(id), '
            . 'office_id VARCHAR(26) NOT NULL REFERENCES sis_offices(id))');
        $hold = '00000000000000000000000001';
        $office = '00000000000000000000000002';
        $pdo->prepare('INSERT INTO sis_restrictions VALUES (?)')->execute([$hold]);
        $pdo->prepare('INSERT INTO sis_offices VALUES (?)')->execute([$office]);
        $pdo->prepare('INSERT INTO sis_restriction_offices VALUES (?, ?)')->execute([$hold, $office]);
        $migration = new \AddSisHoldFollowUp('hold-follow-up');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        $row = $pdo->query('SELECT * FROM sis_restriction_offices')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($hold, $row['id']);
        self::assertSame($office, $row['office_id']);
        self::assertNull($row['assigned_user_id']);
        self::assertNull($row['follow_up_on']);
        self::assertSame(0, $row['work_revision']);
        self::assertCount(3, $pdo->query('PRAGMA foreign_key_list(sis_restriction_offices)')->fetchAll());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->expectException(\PDOException::class);
        $pdo->prepare('UPDATE sis_restriction_offices SET assigned_user_id=?')->execute([$office]);
    }
}
