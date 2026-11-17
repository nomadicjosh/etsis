<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class AdmissionUpdatesMigrationTest extends TestCase
{
    public function testOperationalNoticeReferencesCompileAcrossFiveDialectsWithoutAddingAnEventStore(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261117000003_CreateSisAdmissionUpdates.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
            $schema->method('create')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new CreateTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \CreateSisAdmissionUpdates('20261117000003');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = [];
            foreach ($definitions as $definition) {
                array_push($commands, ...new $class($connection)->create($definition));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(1, $definitions);
            self::assertStringContainsString('sis_admission_updates', $sql);
            self::assertStringContainsString('read_revision', $sql);
            self::assertStringContainsString('sau_application_owner', $sql);
            self::assertStringContainsString('sau_owner_page', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
