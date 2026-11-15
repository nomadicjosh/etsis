<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class PersonalTimetableMigrationTest extends TestCase
{
    public function testOnlyOwnerAndTermLookupIndexesAreAddedAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000013_AddSisPersonalTimetableIndexes.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $definitions = [];
            $schema = $this->createStub(Schema::class);
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
            $migration = new \AddSisPersonalTimetableIndexes('personal-timetable');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $commands = [];
            foreach ($definitions as $definition) {
                array_push($commands, ...new $class($connection)->alter($definition));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertCount(4, $definitions);
            $indexes = ['ss_faculty_timetable', 'se_student_timetable', 'sst_person_timetable', 'sp_owner_timetable'];
            foreach ($indexes as $index) {
                self::assertStringContainsString($index, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|CREATE TABLE/i', $sql);
            self::assertStringNotContainsString('sis_history', $sql);
        }
    }
}
