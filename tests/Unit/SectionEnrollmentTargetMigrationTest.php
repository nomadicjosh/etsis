<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class SectionEnrollmentTargetMigrationTest extends TestCase
{
    public function testNullableReferencesCompileAcrossSupportedDialectsWithoutRewritingPoliciesOrEvents(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261120000004_AddSisSectionEnrollmentTargets.php";
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
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \AddSisSectionEnrollmentTargets("20261120000004");
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
            self::assertCount(2, $definitions);
            self::assertStringContainsString("minimum_enrollment", $sql);
            self::assertStringContainsString("sis_section_versions", $sql);
            self::assertStringNotContainsString("NOT NULL", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|CREATE TABLE|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
}
