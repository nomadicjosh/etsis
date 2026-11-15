<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class SectionMeetingPlanMigrationTest extends TestCase
{
    public function testEmptyPointerAndBookingIndexCompileWithoutRewritingFactsAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000012_CreateSisSectionMeetingPlans.php';
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
            $schema->method('alter')->willReturnCallback(
                static function (string $name, callable $callback) use (&$definitions): void {
                    $table = new AlterTable($name);
                    $callback($table);
                    $definitions[] = $table;
                }
            );
            $connection = $this->createStub(Connection::class);
            $connection->method('getSchema')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $migration = new \CreateSisSectionMeetingPlans('section-meeting-plans');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = [];
            foreach ($definitions as $definition) {
                $compiler = new $class($connection);
                array_push($commands, ...($definition instanceof CreateTable
                    ? $compiler->create($definition) : $compiler->alter($definition)));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(2, $definitions);
            self::assertStringContainsString('sis_section_meeting_plans', $sql);
            self::assertStringContainsString('sis_sections', $sql);
            self::assertStringContainsString('ssmp_publication_uq', $sql);
            self::assertStringContainsString('sbs_booking_active', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
