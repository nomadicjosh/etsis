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

final class GraduationMigrationTest extends TestCase
{
    public function testDefinitionCompilesForFiveDialectsAndRerunPreservesFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $original = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ];
        $student = $f->student($f->user());
        $assignment = $f->sql->rows(
            "sis_student_programs",
            ["student_id" => $student->id->value],
            1,
        )[0]["id"];
        $f->sql->insert("sis_graduation_cases", [
            "id" => $assignment,
            "student_id" => $student->id->value,
            "version" => 1,
            "eligible" => 1,
            "source_hash" => str_repeat("a", 64),
        ]);
        $before = $f->sql->rows("sis_graduation_cases");
        $itemsToCheck1 = [
            "MySQL",
            "PostgreSQL",
            "SQLite",
            "SQLServer",
            "Oracle",
        ];
        foreach ($itemsToCheck1 as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema
                ->method("create")
                ->willReturnCallback(static function (
                    string $name,
                    callable $callback,
                ) use (&$definitions): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $definitions[] = $table;
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
            $migration = new \CreateSisGraduation("graduation");
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
            $sql = json_encode(
                new $class($connection)->create($definitions[0]),
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(1, $definitions);
            self::assertStringContainsString("sis_graduation_cases", $sql);
            self::assertStringContainsString("source_hash", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
        $rerun = new \CreateSisGraduation("graduation");
        $rerun->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $rerun->up();
        $rerun->up();
        self::assertSame($before, $f->sql->rows("sis_graduation_cases"));
        self::assertSame($original, [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ]);
    }
}
