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
use SisFixture;

final class ApplicationRevisionsMigrationTest extends TestCase
{
    public function testCorrectionStorageCompilesAcrossSupportedDialectsWithoutCreatingAnotherJournal(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $creates = [];
            $alters = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('getColumns')->willReturnCallback(static fn(string $table): array =>
                $table === 'sis_application_revisions'
                    ? ['id', 'application_id', 'owner_user_id', 'status', 'version', 'sealed_data'] : []);
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback,
                ) use (&$creates): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $creates[] = $table;
                });
            $schema
                ->method("alter")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback,
                ) use (&$alters): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $alters[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("queryBuilder")
                ->willReturn($f->connection->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $s): string => '"' . $s . '"',
                );
            $migration = new \CreateSisApplicationRevisions("20261117000009");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter(
                        $connection,
                        "migration",
                    ),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $commands = [];
            foreach ($creates as $table) {
                array_push($commands, ...$compiler->create($table));
            }
            foreach ($alters as $table) {
                array_push($commands, ...$compiler->alter($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(1, $creates);
            self::assertCount(3, $alters);
            self::assertStringContainsString("sis_application_revisions", $sql);
            self::assertStringContainsString("sealed_data", $sql);
            self::assertStringContainsString("sar_application_state", $sql);
            self::assertStringNotContainsString("sis_history_events", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT/i",
                $sql,
            );
        }
        $p = $f->sql->one("sis_access_permissions", [
            "name" => "sis:admissions:amend",
        ]);
        self::assertSame(1, (int) $p["is_system"]);
        $edges = $f->sql->rows("sis_access_implications", [
            "descendant_id" => $p["id"],
        ]);
        self::assertCount(1, $edges);
        self::assertSame($p["id"], $edges[0]["ancestor_id"]);
        self::assertSame(
            [],
            $f->sql->rows("sis_access_role_permissions", [
                "permission_id" => $p["id"],
            ]),
        );
        self::assertSame(
            [],
            $f->sql->rows("sis_access_user_permissions", [
                "permission_id" => $p["id"],
            ]),
        );
    }
}
