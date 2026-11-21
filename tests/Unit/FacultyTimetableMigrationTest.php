<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use SisFixture;

final class FacultyTimetableMigrationTest extends TestCase
{
    public function testFiveDialectCompilationAndPartialMigrationRetryPreserveExistingFacts(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $tables = [
            "sis_registration_policy",
            "sis_section_versions",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_access_role_permissions",
            "sis_access_user_roles",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $f->connection->pdo->exec("DROP INDEX ssv_faculty_dates_idx");
        $migration = new \AddSisFacultyTimetablePolicy("faculty-timetable");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($f->connection, "migration")]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $f->connection->pdo->exec("DROP INDEX ssv_faculty_dates_idx");
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
            $connection->method("queryBuilder")->willReturn($f->connection->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration->setObjectMap(
                new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $commands = [];
            foreach ($definitions as $table) {
                array_push($commands, ...new $compiler($connection)->alter($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $definitions);
            self::assertStringContainsString("faculty_schedule_mode", $sql);
            self::assertStringContainsString("ssv_faculty_dates_idx", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
    }
}
