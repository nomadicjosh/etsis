<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class AttendanceVersionMigrationTest extends TestCase
{
    public function testVersionColumnCompilesForFiveFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261118000003_AddSisAttendanceVersions.php";
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
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisAttendanceVersions("20261118000003");
            $migration->setObjectMap(
                new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString("version", $sql);
            self::assertStringContainsString("NOT NULL", $sql);
            self::assertMatchesRegularExpression("/DEFAULT.*1/i", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|sis_history_events/i",
                $sql,
            );
        }
    }

    public function testUpgradeAndRerunsPreserveEarlierMarksEventsAndOtherWorkingFacts(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $student = $f->student();
        $section = $f->section(faculty: $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $f->gradebook()->attendance($student->id, $section->id, new DateTimeImmutable("2026-08-11"), "late");
        $f->connection->query("ALTER TABLE sis_attendance DROP COLUMN version");
        $original = $f->sql->rows("sis_attendance");
        $tables = [
            "sis_scores",
            "sis_academic_credits",
            "sis_history_events",
            "sis_enrollments",
            "sis_access_user_roles",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $migration = new \AddSisAttendanceVersions("20261118000003");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($f->connection, "migration")]),
        );
        $migration->up();
        $upgraded = $f->sql->rows("sis_attendance");
        self::assertSame(1, $upgraded[0]["version"]);
        unset($upgraded[0]["version"]);
        self::assertSame($original, $upgraded);
        $mark = $f->gradebook()->attendanceHistory($student->id, $section->id)["items"][0];
        $f->gradebook()->attendance(
            $student->id,
            $section->id,
            new DateTimeImmutable("2026-08-11"),
            "present",
            $mark["revision"],
        );
        $saved = $f->sql->rows("sis_attendance");
        $migration->up();
        self::assertSame(2, $saved[0]["version"]);
        self::assertSame($saved, $f->sql->rows("sis_attendance"));
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
    }
}
