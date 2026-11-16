<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

final class PublicEventMigrationTest extends TestCase
{
    public function testPublicContentAndTimesCompileAcrossAllFiveFrameworkDialectsWithoutChangingHistory(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000014_CreateSisPublicEvents.php';
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
            $migration = new \CreateSisPublicEvents('20261115000014');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = [];
            foreach ($definitions as $definition) {
                array_push($commands, ...new $class($connection)->create($definition));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $definitions);
            self::assertStringContainsString('sis_public_events', $sql);
            self::assertStringContainsString('sis_public_event_times', $sql);
            self::assertStringContainsString('spet_calendar', $sql);
            self::assertStringContainsString('sis_booking_slots', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
