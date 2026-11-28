<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class LedgerControlMigrationTest extends TestCase
{
    public function testFiveDialectDefinitionsAndRepeatedMigrationPreserveFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $dialects = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($dialects as $dialect) {
            $connection = $this->createStub(Connection::class);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $name): string => '"' . $name . '"',
                );
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $tables = [
                "sis_gl_gate" => "gate",
                "sis_gl_books" => "books",
                "sis_gl_periods" => "periods",
                "sis_gl_movements" => "movements",
            ];
            foreach ($tables as $name => $method) {
                $table = new CreateTable($name);
                \CreateSisLedgerControls::$method($table);
                $sql = json_encode(
                    new $compiler($connection)->create($table),
                    JSON_THROW_ON_ERROR,
                );
                self::assertStringContainsString($name, $sql);
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                    $sql,
                );
            }
        }
        $before = [];
        $preservedTables = [
            "sis_history_events",
            "sis_gl_gate",
            "sis_access_permissions",
            "sis_access_implications",
            "sis_access_role_permissions",
            "sis_access_user_roles",
        ];
        foreach ($preservedTables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        $migration = new \CreateSisLedgerControls("ledger");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $s->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $s->sql->rows($table));
        }
        self::assertCount(
            1,
            $s->sql->rows("sis_access_permissions", [
                "name" => "sis:finance:close",
            ]),
        );
    }
}
