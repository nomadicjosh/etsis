<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class TuitionMigrationTest extends TestCase
{
    public function testFivePortableDefinitionsAndRepeatedSetupRetainOriginalFactsAndGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $dialects = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($dialects as $dialect) {
            $connection = $this->createStub(Connection::class);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(
                    static fn(string $v): string => '"' . $v . '"',
                );
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $tables = [
                "sis_tuition_schedules" => "schedules",
                "sis_tuition_assignments" => "assignments",
                "sis_tuition_bills" => "bills",
                "sis_tuition_invoices" => "invoices",
            ];
            foreach ($tables as $name => $method) {
                $table = new CreateTable($name);
                \CreateSisTuition::$method($table);
                $compiled = json_encode(
                    new $compiler($connection)->create($table),
                    JSON_THROW_ON_ERROR,
                );
                self::assertStringContainsString($name, $compiled);
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE FROM|AUTO_INCREMENT/i",
                    $compiled,
                );
            }
        }
        $tables = [
            "sis_history_events",
            "sis_tuition_schedules",
            "sis_tuition_assignments",
            "sis_tuition_bills",
            "sis_tuition_invoices",
            "sis_access_permissions",
            "sis_access_user_permissions",
            "sis_access_user_roles",
            "sis_access_role_permissions",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \CreateSisTuition("tuition");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $s->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
    }
}
