<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;
use SisFixture;

final class GradeChangeApprovalMigrationTest extends TestCase
{
    public function testApprovalDefinitionsCompileForFiveDialectsAndPreserveFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $original = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ];
        $f->sql->update(
            "sis_grade_change_policy",
            ["mode" => "approval", "revision" => 2],
            [
                "id" => \Domain\AcademicRecord\Repository\GradeChangeRepository::POLICY_ID,
            ],
        );
        $itemsToCheck1 = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($itemsToCheck1 as $dialect) {
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
            $migration = new \CreateSisGradeChangeApprovals("grade-change-approval");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                array_map(
                    static fn(CreateTable $table) => new $class($connection)->create($table),
                    $definitions,
                ),
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(2, $definitions);
            self::assertStringContainsString("sis_grade_change_policy", $sql);
            self::assertStringContainsString("mode", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
        self::assertSame(
            "approval",
            $f->sql->one("sis_grade_change_policy", [
                "id" => \Domain\AcademicRecord\Repository\GradeChangeRepository::POLICY_ID,
            ])["mode"],
        );
        self::assertSame($original, [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ]);
    }
}
