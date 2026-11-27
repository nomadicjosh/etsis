<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class InternalTransferMigrationTest extends TestCase
{
    public function testSubtypeAndUnifiedSourceViewCompileOnFiveDialectsAndRepeatPreservesFacts(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $before = [];
        foreach (
            [
                "sis_history_events",
                "sis_academic_credits",
                "sis_access_permissions",
                "sis_access_role_permissions",
                "sis_transfer_awards",
            ] as $table
        ) {
            $before[$table] = $f->sql->rows($table);
        }
        $connection = $this->createStub(Connection::class);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(
                static fn(string $s): string => '"' . $s . '"',
            );
        foreach (
            ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect
        ) {
            $table = new CreateTable("sis_internal_transfer_awards");
            \CreateSisInternalTransfers::define($table);
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $compiler($connection)->create($table),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("sita_source_once", $sql);
            self::assertStringContainsString("source_credit_id", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events|COLLATE/i",
                $sql,
            );
        }
        $view = \CreateSisInternalTransfers::viewSql($connection);
        self::assertStringContainsString("UNION ALL", $view);
        self::assertStringContainsString("same_institution", $view);
        self::assertStringContainsString("sis_transfer_awards", $view);
        $migration = new \CreateSisInternalTransfers("internal-transfer");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
        self::assertSame([], $f->sql->rows("sis_internal_transfer_awards"));
    }
}
