<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class SectionReportingGroupMigrationTest extends TestCase
{
    public function testReferencesAndUnknownFlagsCompileForFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261123000000_AddSisSectionReportingGroups.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $definitions = $commands = [];
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
            $pdo = $this->createStub(PDO::class);
            $pdo->method("getAttribute")->willReturn($dialect === "SQLite" ? "sqlite" : "mysql");
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get("pdo"))->willReturn($pdo);
            $connection->method("getSchema")->willReturn($schema);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $connection
                ->method("command")
                ->willReturnCallback(static function (string $sql) use (&$commands): bool {
                    $commands[] = $sql;
                    return true;
                });
            $migration = new \AddSisSectionReportingGroups("reporting-groups");
            $migration->setObjectMap(
                new ArrayObject([
                    "phpmig.adapter" => new DbalMigrationAdapter($connection, "migration"),
                ]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [...array_map($compiler->alter(...), $definitions), ...$commands],
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(5, count($definitions) + count($commands));
            self::assertStringContainsString("reporting_group_id", $sql);
            self::assertStringContainsString("reporting_group_recorded", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |UPDATE |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
            self::assertStringNotContainsString("sis_history_events", $sql);
        }
    }
    public function testPopulatedEarlierReferencesRemainUnrecordedAcrossMigrationRetries(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261117000012_CreateSisSectionClassifications.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000000_AddSisSectionReportingGroups.php';
        $db = new \Qubus\Expressive\Connection\Pdo\Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        foreach (['sis_sections', 'sis_section_versions', 'sis_catalog_classifications'] as $table) {
            $db->pdo->exec('CREATE TABLE ' . $table . ' (id VARCHAR(26) PRIMARY KEY)');
        }
        $section = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $version = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $campus = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $parents = ['sis_sections' => $section, 'sis_section_versions' => $version,
            'sis_catalog_classifications' => $campus];
        foreach ($parents as $table => $id) {
            $db->pdo->prepare('INSERT INTO ' . $table . ' (id) VALUES (?)')->execute([$id]);
        }
        $map = new ArrayObject(['phpmig.adapter' => new DbalMigrationAdapter($db, 'migration')]);
        $old = new \CreateSisSectionClassifications('old-reporting-fixture');
        $old->setObjectMap($map);
        $old->up();
        $db->pdo->prepare('INSERT INTO sis_section_classifications (id, campus_id) VALUES (?, ?)')
            ->execute([$section, $campus]);
        $db->pdo->prepare(
            'INSERT INTO sis_section_version_classes (id, campus_id, campus_code, campus_title) VALUES (?, ?, ?, ?)'
        )->execute([$version, $campus, 'OLD', 'Fictional retained campus']);
        $before = [];
        foreach (['sis_section_classifications', 'sis_section_version_classes'] as $table) {
            $before[$table] = $db->pdo->query('SELECT * FROM ' . $table)->fetch(\PDO::FETCH_ASSOC);
        }
        $migration = new \AddSisSectionReportingGroups('reporting-fixture');
        $migration->setObjectMap($map);
        $migration->up();
        $migration->up();
        foreach ($before as $table => $row) {
            $after = $db->pdo->query('SELECT * FROM ' . $table)->fetch(\PDO::FETCH_ASSOC);
            self::assertSame($row, array_intersect_key($after, $row));
            self::assertNull($after['reporting_group_id']);
            if ($table === 'sis_section_version_classes') {
                self::assertNull($after['reporting_group_code']);
                self::assertNull($after['reporting_group_title']);
                self::assertSame(0, (int) $after['reporting_group_recorded']);
            }
        }
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
