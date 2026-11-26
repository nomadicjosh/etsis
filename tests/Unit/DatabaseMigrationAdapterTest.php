<?php

declare(strict_types=1);

namespace Tests\Unit;

use Infrastructure\Persistence\DatabaseMigrationAdapter;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Migration;

final class DatabaseMigrationAdapterTest extends TestCase
{
    private function connection(): Sqlite
    {
        // Reproduce PostgreSQL's advertised capability without requiring a live engine for this regression.
        $connection = new class (['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']) extends Sqlite {
            public function supportsReturning(): bool
            {
                return true;
            }
        };
        $connection->pdo->exec('CREATE TABLE migration (version VARCHAR(255) PRIMARY KEY, recorded_on VARCHAR(19))');
        $connection->pdo->exec("INSERT INTO migration VALUES ('earlier', '2026-08-01 13:00:00')");
        return $connection;
    }

    public function testPreparedMigrationReceiptsNeedNoGeneratedReturningColumnAndPreserveEarlierRows(): void
    {
        $connection = $this->connection();
        $before = $connection->pdo->query('SELECT * FROM migration')->fetchAll();
        $adapter = new DatabaseMigrationAdapter($connection, 'migration');
        $connection->transactional(fn () => $adapter->up(new Migration('20261126000004')));
        self::assertSame(['20261126000004', 'earlier'], $adapter->fetchAll());
        $earlier = $connection->pdo->query("SELECT * FROM migration WHERE version='earlier'")->fetchAll();
        self::assertSame($before, $earlier);
        $at = $connection->pdo->query(
            "SELECT recorded_on FROM migration WHERE version='20261126000004'"
        )->fetchColumn();
        self::assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $at);
    }

    public function testAReceiptFailureRollsBackTheSchemaAndDoesNotReplaceEarlierReceipts(): void
    {
        $connection = $this->connection();
        $adapter = new DatabaseMigrationAdapter($connection, 'migration');
        $before = $connection->pdo->query('SELECT * FROM migration')->fetchAll();
        $failure = null;
        try {
            $connection->transactional(function () use ($connection, $adapter): void {
                $connection->pdo->exec('CREATE TABLE fictional_new_projection (id VARCHAR(26) PRIMARY KEY)');
                $adapter->up(new Migration('earlier'));
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertSame($before, $connection->pdo->query('SELECT * FROM migration')->fetchAll());
        self::assertSame(0, (int) $connection->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='fictional_new_projection'"
        )->fetchColumn());
    }
}
