<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Domain\Shared\ValueObject\EntityId;

final class PrerequisiteCreditOriginMigrationTest extends TestCase
{
    public function testNullableReferencesCompileAcrossSupportedDialectsWithoutRewritingPoliciesOrEvents(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261123000008_AddSisPrerequisiteCreditOrigin.php";
        $dialects = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($dialects as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("getColumns")->willReturn([]);
            $schema
                ->method("alter")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback
                ) use (
                    &$definitions,
                ): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisPrerequisiteCreditOrigin("20261123000008");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $commands = [];
            foreach ($definitions as $table) {
                array_push($commands, ...new $compiler($connection)->alter($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $definitions);
            self::assertStringContainsString("prerequisite_credit_origin", $sql);
            self::assertStringContainsString("sis_course_versions", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|CREATE TABLE|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testPopulatedEarlierSqliteRowsRetainTheirBytesAndDefaultToNoNewPolicy(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000008_AddSisPrerequisiteCreditOrigin.php';
        $connection = new Sqlite(['dsn' => 'sqlite::memory:', 'driver' => 'pdo_sqlite']);
        $tables = ['sis_course_prerequisite_groups', 'sis_course_versions'];
        $before = [];
        foreach ($tables as $table) {
            $connection->getSchema()->create($table, static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->text('definition')->notNull();
            });
            $connection->pdo->prepare('INSERT INTO ' . $table . ' VALUES (?, ?)')->execute([
                EntityId::generate()->value, '{"earlier":"fictional retained bytes"}',
            ]);
            $before[$table] = $connection->pdo->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC);
        }
        $migration = new \AddSisPrerequisiteCreditOrigin('origin');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], $connection->pdo->query('SELECT id, definition FROM ' . $table)
                ->fetchAll(\PDO::FETCH_ASSOC));
            $origin = $connection->pdo->query('SELECT prerequisite_credit_origin FROM ' . $table)->fetchColumn();
            self::assertNull($origin);
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
