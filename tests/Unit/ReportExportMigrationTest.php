<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class ReportExportMigrationTest extends TestCase
{
    public function testPrivateExportTablesCompileAcrossSupportedDialectsWithoutChangingHistoricalFacts(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261119000006_CreateSisReportExports.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $tables = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('create')->willReturnCallback(static function ($name, $build) use (&$tables): void {
                $table = new CreateTable($name);
                $build($table);
                $tables[] = $table;
            });
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \CreateSisReportExports('report-exports');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = [];
            foreach ($tables as $table) {
                array_push($commands, ...new $class($connection)->create($table));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $tables);
            self::assertStringContainsString('sis_report_exports', $sql);
            self::assertStringContainsString('sis_report_export_chunks', $sql);
            self::assertStringContainsString('srec_chunk', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
        }
    }
}
