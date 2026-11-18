<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use LogicException;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class TransferCompletionMigrationTest extends TestCase
{
    public function testNullableDateProjectionCompilesWithoutBackfillForFiveFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261118000004_AddSisTransferCompletionDates.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("getColumns")->willReturn(["id", "completed_on"]);
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
            $migration = new \AddSisTransferCompletionDates("20261118000004");
            $migration->setObjectMap(
                new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
            );
            $migration->up();
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString("corrected_completed_on", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|UPDATE |sis_history_events/i",
                $sql,
            );
        }
    }

    public function testRerunsPreserveOriginalAndCorrectedDatesEventsAndAccessGrants(): void
    {
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $h = new \HiatusFixture();
        $t = new \TransferAwardFixture($h->sis, $h->cipher);
        $f = $t->sis;
        $review = $t
            ->periods()
            ->review($t->student, $t->award, null, 0, "Fictional completion evidence.", "2026-05-12");
        $t->periods()->commit($t->student, $t->award, $review["review_token"]);
        $tables = [
            "sis_transfer_awards",
            "sis_academic_credits",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_access_role_permissions",
            "sis_access_user_roles",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $migration = new \AddSisTransferCompletionDates("20261118000004");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($f->connection, "migration")]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $this->expectException(LogicException::class);
        $migration->down();
    }
    public function testAddingTheColumnToAPopulatedTableLeavesOriginalRowsAndNullUnknowns(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261118000004_AddSisTransferCompletionDates.php";
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $connection->pdo->exec(
            "CREATE TABLE sis_transfer_awards (id VARCHAR(26) PRIMARY KEY, completed_on VARCHAR(10) NOT NULL)",
        );
        $connection->pdo->exec(
            "INSERT INTO sis_transfer_awards (id, completed_on) VALUES ('01J00000000000000000000000', '2026-05-01')",
        );
        $before = $connection->pdo->query("SELECT * FROM sis_transfer_awards")->fetchAll(\PDO::FETCH_ASSOC);
        $migration = new \AddSisTransferCompletionDates("20261118000004");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
        );
        $migration->up();
        self::assertSame(
            $before[0] + ["corrected_completed_on" => null],
            $connection->pdo->query("SELECT * FROM sis_transfer_awards")->fetch(\PDO::FETCH_ASSOC),
        );
    }
}
