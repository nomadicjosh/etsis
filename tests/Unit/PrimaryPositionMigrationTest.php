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

final class PrimaryPositionMigrationTest extends TestCase
{
    public function testDefinitionCompilesForFiveDialectsAndRerunPreservesFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $employee = $f
            ->timesheetService()
            ->addEmployee($f->student()->personId, "Fictional employee");
        $f->sql->insert("sis_primary_positions", [
            "employee_id" => $employee->value,
            "encrypted_designation" => "fictional-existing-protected-value",
            "version" => 1,
        ]);
        $original = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ];
        $before = $f->sql->rows("sis_primary_positions");
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
            $migration = new \CreateSisPrimaryPositions("primary-positions");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $class($connection)->create($definitions[0]),
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(1, $definitions);
            self::assertStringContainsString("sis_primary_positions", $sql);
            self::assertStringContainsString("encrypted_designation", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
        $rerun = new \CreateSisPrimaryPositions("primary-positions");
        $rerun->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations"),
            ]),
        );
        $rerun->up();
        $rerun->up();
        self::assertSame($before, $f->sql->rows("sis_primary_positions"));
        self::assertSame($original, [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_access_role_permissions"),
            $f->sql->rows("sis_access_user_roles"),
        ]);
    }
}
