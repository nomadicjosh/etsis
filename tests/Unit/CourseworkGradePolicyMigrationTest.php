<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class CourseworkGradePolicyMigrationTest extends TestCase
{
    public function testNullablePolicyCompilesForFiveDialectsWithoutRewritingHistory(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261121000015_AddSisCourseworkGradePolicy.php";
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $dialects = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($dialects as $dialect) {
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
            $migration = new \AddSisCourseworkGradePolicy("20261121000015");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $commands = [];
            foreach ($definitions as $table) {
                array_push($commands, ...new $compiler($connection)->alter($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString("grade_policy_json", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|CREATE TABLE|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testPopulatedUpgradeAndRerunsPreserveOriginalFactsAndNeverGrantTheDuty(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $section = $f->section();
        $student = $f->student($f->user());
        $f->service->register($student->id, $section->id, true);
        $f->sql->execute("ALTER TABLE sis_gradebook_calculations DROP COLUMN grade_policy_json");
        $f->sql->insert("sis_gradebook_calculations", [
            "id" => $section->id->value,
            "mode" => "points",
            "weights" => "[]",
            "revision" => 2,
        ]);
        $before = $f->sql->rows("sis_gradebook_calculations")[0];
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_scores",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ];
        $facts = array_map($f->sql->rows(...), $tables);
        $migration = new \AddSisCourseworkGradePolicy("20261121000015");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $migration->up();
        $after = $f->sql->rows("sis_gradebook_calculations")[0];
        self::assertNull($after["grade_policy_json"]);
        unset($after["grade_policy_json"]);
        self::assertSame($before, $after);
        $policy = '{"rounding":"half_up","decimal_places":1,"cap_at_hundred":true}';
        $f->sql->update(
            "sis_gradebook_calculations",
            ["grade_policy_json" => $policy],
            ["id" => $section->id->value],
        );
        $permissions = $f->sql->rows("sis_access_permissions");
        $implications = $f->sql->rows("sis_access_implications");
        $migration->up();
        $migration->up();
        self::assertSame(
            $policy,
            $f->sql->rows("sis_gradebook_calculations")[0]["grade_policy_json"],
        );
        self::assertSame($facts, array_map($f->sql->rows(...), $tables));
        self::assertSame($permissions, $f->sql->rows("sis_access_permissions"));
        self::assertSame($implications, $f->sql->rows("sis_access_implications"));
    }
}
