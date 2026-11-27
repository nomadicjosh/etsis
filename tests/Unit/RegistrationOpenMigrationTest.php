<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;
use Tests\Support\RegistrationOpenFixture;

final class RegistrationOpenMigrationTest extends TestCase
{
    public function testBothStateTablesCompileOnFiveDialectsWithoutHistoricalBackfillOrGrants(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261127000005_CreateSisRegistrationOpenStates.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"'
        );
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            foreach (['reads', 'dismissals'] as $kind) {
                $table = new CreateTable('sis_registration_open_' . $kind);
                \CreateSisRegistrationOpenStates::define($table, $kind);
                $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString('PRIMARY KEY', $sql);
                self::assertStringContainsString('owner_uq', $sql);
                self::assertDoesNotMatchRegularExpression(
                    '/INSERT INTO|UPDATE |DELETE FROM|COLLATE|AUTO_INCREMENT/i',
                    $sql
                );
                self::assertStringNotContainsString('sis_history_events', $sql);
            }
        }
    }

    public function testRepeatMigrationPreservesPersonalStateAndEverySourceFact(): void
    {
        require_once dirname(__DIR__) . '/Support/RegistrationOpenFixture.php';
        $f = new RegistrationOpenFixture();
        $f->publish();
        $f->service()->read($f->sis->term, 1);
        $tables = ['sis_registration_open_reads', 'sis_registration_open_dismissals', 'users', 'sis_persons',
            'sis_reg_windows', 'sis_history_events'];
        $before = array_map($f->sis->sql->rows(...), $tables);
        $migration = new \CreateSisRegistrationOpenStates('20261127000005');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->sis->connection, 'migration'),
        ]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sis->sql->rows(...), $tables));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
