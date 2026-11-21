<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class CurriculumComponentMigrationTest extends TestCase
{
    public function testNullableFieldsCompileAcrossAllFiveDialectsWithoutRewritingPublications(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261121000009_AddSisCurriculumComponentKinds.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
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
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $migration = new \AddSisCurriculumComponentKinds("component-kinds");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(array_map($compiler->alter(...), $definitions), JSON_THROW_ON_ERROR);
            self::assertCount(2, $definitions);
            self::assertStringContainsString("kind_limits", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |UPDATE |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testPopulatedEarlierReferencesAndPoliciesKeepTheirActualValuesAndUnknownRoles(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationFixture.php";
        $f = new \StudentSpecializationFixture();
        $s = $f->sis;
        foreach ($s->sql->rows("sis_program_specializations") as $row) {
            $s->sql->update("sis_program_specializations", ["kind" => null], ["id" => $row["id"]]);
        }
        $s->sql->update(
            "sis_specialization_policies",
            ["kind_limits" => null],
            ["id" => $s->program->value],
        );
        $tables = [
            "sis_program_specializations",
            "sis_specialization_policies",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_audit",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $s->sql->execute("ALTER TABLE sis_program_specializations DROP COLUMN kind");
        $s->sql->execute("ALTER TABLE sis_specialization_policies DROP COLUMN kind_limits");
        $migration = new \AddSisCurriculumComponentKinds("component-kinds");
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
