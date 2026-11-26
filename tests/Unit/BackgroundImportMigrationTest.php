<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class BackgroundImportMigrationTest extends TestCase
{
    public function testAdditiveImportTablesCompileForAllFiveSupportedDialectsWithoutChangingRecordedFacts(): void
    {
        $definitions = [];
        $schema = $this->createMock(Schema::class);
        $schema
            ->expects(self::exactly(4))
            ->method("create")
            ->willReturnCallback(static function (
                string $name,
                callable $define,
            ) use (&$definitions): void {
                $table = new CreateTable($name);
                $define($table);
                $definitions[] = $table;
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(
                static fn(string $id): string => '"' . $id . '"',
            );
        $iterationValues = [
            "20261125000013_CreateSisPersonImports",
            "20261125000014_CreateSisBackgroundImports",
            ];
        foreach ($iterationValues as $file) {
            require_once dirname(__DIR__, 2) .
            "/database/migrations/" .
            $file .
            ".php";
            $class = substr($file, 15);
            $migration = new $class(substr($file, 0, 14));
            $migration->setObjectMap(
                new \ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter(
                        $connection,
                        "migration",
                    ),
                ]),
            );
            $migration->up();
        }
        $iterationValues = ["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"];
        foreach ($iterationValues as $dialect) {
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            foreach ($definitions as $definition) {
                $sql = json_encode(
                    new $compiler($connection)->create($definition),
                    JSON_THROW_ON_ERROR,
                );
                self::assertStringContainsString("CREATE TABLE", $sql);
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE FROM|INSERT INTO|UPDATE |AUTO_INCREMENT|information_schema|COLLATE/i",
                    $sql,
                );
            }
        }
    }
}
