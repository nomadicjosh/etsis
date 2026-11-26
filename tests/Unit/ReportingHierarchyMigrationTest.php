<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class ReportingHierarchyMigrationTest extends TestCase
{
    public function testEntireMigrationCompilesAcrossAllFiveSchemaDialects(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261125000012_AddSisReportingHierarchy.php";
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $creates = $alters = [];
            $schema = $this->createStub(Schema::class);
            $schema->method("hasTable")->willReturn(false);
            $schema->method("getColumns")->willReturn([]);
            $schema
                ->method("create")
                ->willReturnCallback(static function (string $name, callable $define) use (&$creates): void {
                    $table = new CreateTable($name);
                    $define($table);
                    $creates[] = $table;
                });
            $schema
                ->method("alter")
                ->willReturnCallback(static function (string $name, callable $define) use (&$alters): void {
                    $table = new AlterTable($name);
                    $define($table);
                    $alters[] = $table;
                });
            $db = new Sqlite(["driver" => "pdo_sqlite", "dsn" => "sqlite::memory:"]);
            $db->pdo->exec(
                "CREATE TABLE sis_reporting_hierarchy_state (id VARCHAR(26) PRIMARY KEY, version INTEGER)",
            );
            $connection = $this->createStub(Connection::class);
            $connection->method("getSchema")->willReturn($schema);
            $connection->method("queryBuilder")->willReturn($db->queryBuilder());
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $migration = new \AddSisReportingHierarchy("compiler");
            $migration->setObjectMap(
                new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "migration")]),
            );
            $migration->up();
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $commands = [
                array_map($compiler->create(...), $creates),
                array_map($compiler->alter(...), $alters),
            ];
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $creates);
            self::assertCount(5, $alters);
            $fragments = [
                "FOREIGN KEY",
                "PRIMARY KEY",
                "reporting_group_ancestors",
                "reporting_root_title",
                "reporting_hierarchy_recorded",
            ];
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
    }

    public function testPopulatedEarlierFactsRemainUnchangedAndRetriesPreserveTechnicalState(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261125000012_AddSisReportingHierarchy.php";
        $db = new Sqlite(["driver" => "pdo_sqlite", "dsn" => "sqlite::memory:"]);
        $db->pdo->exec("PRAGMA foreign_keys = ON");
        $db->pdo->exec("CREATE TABLE sis_catalog_classifications (id VARCHAR(26) PRIMARY KEY)");
        $db->pdo->exec(
            "CREATE TABLE sis_section_version_classes (id VARCHAR(26) PRIMARY KEY, " .
                "reporting_group_id VARCHAR(26), reporting_group_code VARCHAR(40), " .
                "reporting_group_title VARCHAR(191), " .
                "reporting_group_recorded INTEGER NOT NULL)",
        );
        $sql = new SqlRecords($db->queryBuilder());
        $id = EntityId::generate()->value;
        $group = EntityId::generate()->value;
        $sql->insert("sis_catalog_classifications", ["id" => $group]);
        $old = [
            "id" => $id,
            "reporting_group_id" => $group,
            "reporting_group_code" => "OLD",
            "reporting_group_title" => "Earlier retained group",
            "reporting_group_recorded" => 1,
        ];
        $sql->insert("sis_section_version_classes", $old);
        $migration = new \AddSisReportingHierarchy("populated");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($db, "migration")]),
        );
        $migration->up();
        self::assertSame($old, array_intersect_key($sql->current("sis_section_version_classes", $id), $old));
        $new = $sql->current("sis_section_version_classes", $id);
        self::assertNull($new["reporting_group_ancestors"]);
        self::assertNull($new["reporting_root_id"]);
        self::assertNull($new["reporting_root_code"]);
        self::assertNull($new["reporting_root_title"]);
        self::assertSame(0, (int) $new["reporting_hierarchy_recorded"]);
        self::assertSame([], $sql->rows("sis_reporting_hierarchy"));
        $sql->update(
            "sis_reporting_hierarchy_state",
            ["version" => 7],
            ["id" => ReportingHierarchyRepository::STATE_ID],
        );
        $migration->up();
        self::assertSame($new, $sql->current("sis_section_version_classes", $id));
        self::assertSame(
            7,
            (int) $sql->current("sis_reporting_hierarchy_state", ReportingHierarchyRepository::STATE_ID)[
                "version"
            ],
        );
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
