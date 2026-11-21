<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;
use SisFixture;

final class WaitlistMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    public function testQueueSupportTablesCompileOnEveryFrameworkDialectWithoutAnotherJournal(): void
    {
        $s = new SisFixture();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback
                ) use (
                    &$definitions,
                ): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $definitions[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $migration = new \CreateSisWaitlists("waitlists");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            self::assertCount(2, $definitions);
            $sql = json_encode(
                array_map($compiler->create(...), $definitions),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("encrypted_state", $sql);
            self::assertStringContainsString("swle_accepted_order", $sql);
            self::assertStringNotContainsString("sis_history_events", $sql);
            self::assertDoesNotMatchRegularExpression("/DROP |DELETE FROM|AUTO_INCREMENT/i", $sql);
        }
    }

    public function testRerunPreservesHistoryAndPermissionsWithoutImplicitGrants(): void
    {
        $s = new SisFixture();
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_access_permissions",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \CreateSisWaitlists("waitlists");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "migration"),
            ]),
        );
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $permission = $s->sql->one("sis_access_permissions", [
            "name" => "sis:registration:waitlist",
        ]);
        self::assertSame(1, (int) $permission["is_system"]);
        self::assertSame(
            [],
            $s->sql->rows("sis_access_role_permissions", ["permission_id" => $permission["id"]]),
        );
        self::assertSame(
            [],
            $s->sql->rows("sis_access_user_permissions", ["permission_id" => $permission["id"]]),
        );
    }
}
