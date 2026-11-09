<?php

declare(strict_types=1);
use Infrastructure\Persistence\GradeDefinitionSchemaUpgrade;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;
final class VersionedGradeSchemaTest extends TestCase
{
    public function testVersionedGradeTableAndUpgradeColumnsCompileForAllFiveDialects(): void
    {
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $connection = $this->createStub(Connection::class);
            $connection->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    "MySQL" => "`" . $name . "`",
                    "SQLServer" => "[" . $name . "]",
                    default => '"' . $name . '"',
                },
            );
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $compiler = new $class($connection);
            $table = new CreateTable("sis_grades_upgrade");
            GradeDefinitionSchemaUpgrade::table($table);
            $compiled = $compiler->create($table);
            self::assertNotEmpty($compiled, $driver);
            self::assertStringContainsString("scale_version_id", json_encode($compiled, JSON_THROW_ON_ERROR));
            self::assertStringContainsString("sg_version_code", json_encode($compiled, JSON_THROW_ON_ERROR));
            $columns = new AlterTable("sis_grades");
            GradeDefinitionSchemaUpgrade::columns($columns);
            self::assertCount(5, $compiler->alter($columns), $driver);
            if ($driver !== "SQLite") {
                $drop = new AlterTable("sis_grades");
                $drop->dropUnique("sis_w2_u0");
                self::assertCount(1, $compiler->alter($drop), $driver);
            }
        }
    }
}
