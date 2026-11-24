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

final class ProgramDepartmentOwnershipMigrationTest extends TestCase
{
    public function testExplicitOwnershipCompilesForAllFiveSchemaDialectsWithoutDataRewrites(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261123000006_CreateSisProgramDepartments.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = $alterations = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema->method("getColumns")->willReturn([]);
            $schema
                ->method("alter")
                ->willReturnCallback(static function (
                    string $name,
                    callable $build
                ) use (
                    &$alterations,
                ): void {
                    $table = new AlterTable($name);
                    $build($table);
                    $alterations[] = $table;
                });
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $build
                ) use (
                    &$definitions,
                ): void {
                    $table = new CreateTable($name);
                    $build($table);
                    $definitions[] = $table;
                });
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $migration = new \CreateSisProgramDepartments("joint-program-ownership");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [
                    ...array_map($compiler->create(...), $definitions),
                    ...array_map($compiler->alter(...), $alterations),
                ],
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(2, $definitions);
            self::assertCount(1, $alterations);
            self::assertStringContainsString("department_ownership_recorded", $sql);
            self::assertStringContainsString("snapshot_hash", $sql);
            self::assertStringContainsString("sis_program_departments", $sql);
            self::assertStringContainsString("spd_owner_unique", $sql);
            self::assertStringContainsString("spd_program_fk", $sql);
            self::assertStringContainsString("spd_department_fk", $sql);
            self::assertStringContainsString("26", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |UPDATE |DELETE FROM|INSERT INTO|AUTO_INCREMENT|sis_history_|sis_role_/i",
                $sql,
            );
        }
    }

    public function testPopulatedRecordsAndHistorySurviveRerunsWithoutInferredJointOwners(): void
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
        $s->sql->execute("DROP TABLE sis_program_departments");
        $s->sql->execute("DROP TABLE sis_program_catalog_ownership");
        $s->sql->execute(
            "ALTER TABLE sis_program_catalog_details DROP COLUMN department_ownership_recorded",
        );
        $migration = new \CreateSisProgramDepartments("joint-program-ownership");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "migration"),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        self::assertSame([], $s->sql->rows("sis_program_departments"));
        self::assertSame([], $s->sql->rows("sis_program_catalog_ownership"));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
