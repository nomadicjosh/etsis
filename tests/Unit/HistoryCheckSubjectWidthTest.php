<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Shared\ValueObject\EntityId;
use Domain\Support\Enum\HistoryCheckSubject;
use Infrastructure\Persistence\Migration\HistoryCheckSubjectWidth;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class HistoryCheckSubjectWidthTest extends TestCase
{
    public function testDialectStatementsOnlyWidenTheKnownRoutingColumn(): void
    {
        $quote = static fn(string $name): string => '"' . $name . '"';
        foreach (['mysql', 'pgsql', 'sqlsrv', 'dblib', 'oci'] as $driver) {
            $statement = HistoryCheckSubjectWidth::statement($driver, $quote);
            self::assertStringContainsString('sis_history_checks', $statement);
            self::assertStringContainsString('subject_type', $statement);
            self::assertStringContainsString('32', $statement);
            self::assertDoesNotMatchRegularExpression(
                '/DROP|DELETE|UPDATE|sis_history_events|sis_access_/i',
                $statement,
            );
        }
        self::assertNull(HistoryCheckSubjectWidth::statement('sqlite', $quote));
        foreach (HistoryCheckSubject::cases() as $kind) {
            self::assertLessThanOrEqual(32, strlen($kind->value));
        }
        $this->expectException(\LogicException::class);
        HistoryCheckSubjectWidth::statement('unsupported', $quote);
    }

    public function testPopulatedSqlitePreservesAllReceiptsIndexesAndLongApplicationRouting(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000007_ExpandSisHistoryCheckSubjects.php';
        $connection = new Sqlite(['dsn' => 'sqlite::memory:', 'driver' => 'pdo_sqlite']);
        $connection->getSchema()->create('sis_history_checks', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('subject_type', 8)->notNull();
            $t->string('subject_id', 26)->notNull();
            $t->string('person_id', 26)->notNull();
            $t->index(['subject_type', 'subject_id', 'id'], 'shc_subject_page');
        });
        foreach (HistoryCheckSubject::cases() as $kind) {
            $connection->pdo->prepare('INSERT INTO sis_history_checks VALUES (?, ?, ?, ?)')->execute([
                EntityId::generate()->value, $kind->value, EntityId::generate()->value, EntityId::generate()->value,
            ]);
        }
        $before = $connection->pdo->query('SELECT * FROM sis_history_checks ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $schema = $connection->pdo->query('SELECT * FROM sqlite_schema ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        $migration = new \ExpandSisHistoryCheckSubjects('width');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame(
            $before,
            $connection->pdo->query('SELECT * FROM sis_history_checks ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        );
        self::assertSame(
            $schema,
            $connection->pdo->query('SELECT * FROM sqlite_schema ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
        );
        self::assertContains('application', array_column($before, 'subject_type'));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
