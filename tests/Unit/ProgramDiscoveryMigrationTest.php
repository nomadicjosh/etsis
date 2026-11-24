<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class ProgramDiscoveryMigrationTest extends TestCase
{
    public function testExplicitVisibilitySchemaCompilesWithoutPublishingOrConvertingExistingFacts(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261123000012_CreateSisProgramDiscovery.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_program_discovery');
            \CreateSisProgramDiscovery::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $s): string => '"' . $s . '"');
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (['catalog_id', 'publication_hash', 'version', 'spdc_program_fk', 'spdc_catalog_fk'] as $column) {
                self::assertStringContainsString($column, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/INSERT INTO|UPDATE |DELETE FROM|DROP |AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
