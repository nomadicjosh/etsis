<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class CatalogHistoryCheckMigrationTest extends TestCase
{
    private function migration(Connection $connection): \AddSisCatalogHistoryCheckScopes
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261124000005_AddSisCatalogHistoryCheckScopes.php';
        $migration = new \AddSisCatalogHistoryCheckScopes('20261124000005');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration')
        ]));
        return $migration;
    }

    public function testNativeStatementsChangeOnlyTheKnownPersonColumnNullability(): void
    {
        foreach (
            ['mysql' => 'MODIFY COLUMN "person_id" VARCHAR(26) NULL',
            'pgsql' => 'ALTER COLUMN "person_id" DROP NOT NULL',
            'sqlsrv' => 'ALTER COLUMN "person_id" NVARCHAR(26) NULL',
            'dblib' => 'ALTER COLUMN "person_id" NVARCHAR(26) NULL',
            'oci' => 'MODIFY ("person_id" NULL)'] as $driver => $expected
        ) {
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            if ($driver === 'oci') {
                $query = $this->createStub(\PDOStatement::class);
                $query->method('fetchColumn')->willReturn('N');
                $pdo->method('prepare')->willReturn($query);
            }
            $connection = $this->createMock(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $connection->expects(self::once())->method('command')->willReturnCallback(
                static function (string $sql) use ($expected): bool {
                    self::assertSame('ALTER TABLE "sis_history_checks" ' . $expected, $sql);
                    return true;
                }
            );
            $this->migration($connection)->up();
        }
    }

    public function testSqlitePreservesExistingReceiptsCustomColumnsIndexesAndTriggersAndRepeatsSafely(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo = $connection->pdo;
        $pdo->exec('CREATE TABLE sis_history_checks '
            . '(id VARCHAR(26) PRIMARY KEY, person_id VARCHAR(26) NOT NULL, extra TEXT)');
        $pdo->exec("INSERT INTO sis_history_checks VALUES "
            . "('fictional-receipt', 'fictional-person', 'Keep this observation')");
        $pdo->exec('CREATE INDEX fixture_person ON sis_history_checks(person_id, id)');
        $pdo->exec('CREATE TABLE receipt_trigger_calls (id TEXT)');
        $pdo->exec('CREATE TRIGGER receipt_edited AFTER UPDATE ON sis_history_checks '
            . 'BEGIN INSERT INTO receipt_trigger_calls VALUES (NEW.id); END');
        $before = $pdo->query('SELECT * FROM sis_history_checks')->fetchAll(PDO::FETCH_ASSOC);
        $objectsSql = "SELECT type, name, sql FROM sqlite_schema WHERE type IN ('index', 'trigger') ORDER BY name";
        $objects = $pdo->query($objectsSql)->fetchAll(PDO::FETCH_ASSOC);
        $migration = $this->migration($connection);
        $migration->up();
        $migration->up();
        self::assertSame($before, $pdo->query('SELECT * FROM sis_history_checks')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($objects, $pdo->query($objectsSql)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame([], $pdo->query('SELECT * FROM receipt_trigger_calls')->fetchAll(PDO::FETCH_ASSOC));
        $pdo->exec("INSERT INTO sis_history_checks VALUES ('fictional-catalog', NULL, NULL)");
        $query = $pdo->query("SELECT person_id FROM sis_history_checks WHERE id = 'fictional-catalog'");
        self::assertNull($query->fetchColumn());
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
