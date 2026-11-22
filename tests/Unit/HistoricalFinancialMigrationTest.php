<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class HistoricalFinancialMigrationTest extends TestCase
{
    public function testNullableChronologyCompilesForFiveDialectsWithoutRewritingHistory(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261121000018_AddSisHistoricalAccountReports.php";
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
            $migration = new \AddSisHistoricalAccountReports("20261121000018");
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
            self::assertCount(4, $definitions);
            self::assertStringContainsString("reversed_at", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|CREATE TABLE|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
}
