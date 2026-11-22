<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class GradeComparisonPolicyMigrationTest extends TestCase
{
    public function testPolicyStateCompilesForFiveDialectsWithoutRewritingHistory(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261122000000_AddSisGradeComparisonPolicy.php";
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new \SisFixture();
        $dialects = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($dialects as $dialect) {
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
            $connection->method("queryBuilder")->willReturn($f->connection->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisGradeComparisonPolicy("20261122000000");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $commands = [];
            foreach ($definitions as $table) {
                array_push($commands, ...new $compiler($connection)->create($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString("sis_grade_comparison_policy_state", $sql);
            self::assertStringContainsString("version", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
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
        $policy = '{"rounding":"half_up","decimal_places":1,"cap_at_hundred":true}';
        $f->sql->insert("sis_gradebook_calculations", [
            "id" => $section->id->value,
            "mode" => "points",
            "weights" => "[]",
            "revision" => 2,
            "grade_policy_json" => $policy,
        ]);
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_gradebook_calculations",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ];
        $facts = array_map($f->sql->rows(...), $tables);
        $permissions = $f->sql->rows("sis_access_permissions");
        $implications = $f->sql->rows("sis_access_implications");
        $f->sql->execute("DROP TABLE sis_grade_comparison_policy_state");
        $migration = new \AddSisGradeComparisonPolicy("20261122000000");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $migration->up();
        self::assertSame(0, (int) $f->sql->rows("sis_grade_comparison_policy_state")[0]["version"]);
        $f->sql->update(
            "sis_grade_comparison_policy_state",
            ["version" => 5],
            ["id" => \Domain\Gradebook\GradeComparisonPolicy::ID],
        );
        $migration->up();
        $migration->up();
        self::assertSame(5, (int) $f->sql->rows("sis_grade_comparison_policy_state")[0]["version"]);
        self::assertSame($facts, array_map($f->sql->rows(...), $tables));
        self::assertSame($permissions, $f->sql->rows("sis_access_permissions"));
        self::assertSame($implications, $f->sql->rows("sis_access_implications"));
    }
}
