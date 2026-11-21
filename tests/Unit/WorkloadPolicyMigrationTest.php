<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;
use SisFixture;

final class WorkloadPolicyMigrationTest extends TestCase
{
    public function testContactProjectionCompilesForEverySupportedDialectWithoutRewritingEarlierDefinitions(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261121000007_CreateSisSectionContactTime.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema->method("getColumns")->willReturn([]);
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
            $migration = new \CreateSisSectionContactTime("contact-time");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [$compiler->create($definitions[0]), $compiler->alter($definitions[1])],
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("weekly_minutes", $sql);
            self::assertStringContainsString("contact_hours", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |UPDATE |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testPermissionsAreProtectedIdempotentAndNeverAutomaticallyGranted(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new SisFixture();
        $tables = [
            "sis_history_events",
            "sis_timesheets",
            "sis_employees",
            "sis_access_permissions",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \AddSisTimeReviewPermissions("time-review");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "migration"),
            ]),
        );
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        foreach (["sis:hr:time-policy", "sis:hr:time-exception"] as $name) {
            $permission = $s->sql->one("sis_access_permissions", ["name" => $name]);
            self::assertSame(1, (int) $permission["is_system"]);
            self::assertSame(
                [],
                $s->sql->rows("sis_access_role_permissions", [
                    "permission_id" => $permission["id"],
                ]),
            );
            self::assertSame(
                [],
                $s->sql->rows("sis_access_user_permissions", [
                    "permission_id" => $permission["id"],
                ]),
            );
        }
    }
}
