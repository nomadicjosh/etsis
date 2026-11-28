<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class MoodleGradeImportsMigrationTest extends TestCase
{
    public function testPortableDefinitionsAndRepeatPreserveAcademicFactsPermissionsAndNotices(): void
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
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            foreach (["jobs", "candidates", "notices"] as $kind) {
                $table = new CreateTable("sis_moodle_grade_" . $kind);
                \CreateSisMoodleGradeImports::$kind($table);
                $sql = json_encode(
                    new $compiler($connection)->create($table),
                    JSON_THROW_ON_ERROR,
                );
                self::assertStringContainsString(
                    "PRIMARY KEY",
                    strtoupper($sql),
                );
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE FROM|AUTO_INCREMENT|COLLATE/i",
                    $sql,
                );
            }
        }
        $migration = new \CreateSisMoodleGradeImports("20261127000010");
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
        self::assertSame([], $f->sql->rows("sis_moodle_grade_candidates"));
    }
}
