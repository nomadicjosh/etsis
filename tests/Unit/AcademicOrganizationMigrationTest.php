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

final class AcademicOrganizationMigrationTest extends TestCase
{
    public function testNullableReferencesCompileForFiveDialectsWithoutRewritingBusinessFacts(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261121000011_AddSisAcademicOrganization.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = $commands = [];
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
            $pdo = $this->createStub(PDO::class);
            $pdo->method("getAttribute")->willReturn($dialect === "SQLite" ? "sqlite" : "mysql");
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get("pdo"))->willReturn($pdo);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $connection
                ->method("command")
                ->willReturnCallback(static function (string $sql) use (&$commands): bool {
                    $commands[] = $sql;
                    return true;
                });
            $migration = new \AddSisAcademicOrganization("academic-organization");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [...array_map($compiler->alter(...), $definitions), ...$commands],
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(2, count($definitions) + count($commands));
            self::assertStringContainsString("school_id", $sql);
            self::assertStringContainsString("department_id", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |UPDATE |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testEarlierPopulatedReferencesAndEventsSurviveRerunsWithoutInferredOrganization(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationFixture.php";
        $f = new \StudentSpecializationFixture();
        $s = $f->sis;
        $tables = [
            "sis_catalog_classifications",
            "sis_program_specializations",
            "sis_programs",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_audit",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $s->sql->execute("ALTER TABLE sis_catalog_classifications DROP COLUMN school_id");
        $s->sql->execute("ALTER TABLE sis_program_specializations DROP COLUMN department_id");
        $migration = new \AddSisAcademicOrganization("academic-organization");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "migration"),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
    }
}
