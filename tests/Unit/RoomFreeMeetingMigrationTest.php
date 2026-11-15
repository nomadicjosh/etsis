<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PDO;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;

final class RoomFreeMeetingMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000011_CreateSisRoomFreeMeetings.php';
    }

    public function testNativeNullableStatementsKeepTheFrameworkColumnTypes(): void
    {
        foreach (
            ['mysql' => 'MODIFY COLUMN "room_id" VARCHAR(26) NULL',
            'pgsql' => 'ALTER COLUMN "room_id" DROP NOT NULL',
            'sqlsrv' => 'ALTER COLUMN "room_id" NVARCHAR(26) NULL',
            'oci' => 'MODIFY ("room_id" NULL)'] as $driver => $statement
        ) {
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            if ($driver === 'oci') {
                $query = $this->createStub(\PDOStatement::class);
                $query->method('fetchColumn')->willReturn('N');
                $pdo->method('prepare')->willReturn($query);
            }
            $connection = $this->createMock(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $connection->expects(self::exactly(2))->method('command')->willReturnCallback(
                static function (string $sql) use ($statement): bool {
                    self::assertStringContainsString($statement, $sql);
                    self::assertStringStartsWith('ALTER TABLE "sis_booking', $sql);
                    self::assertDoesNotMatchRegularExpression('/DROP TABLE|DELETE FROM|INSERT INTO|UPDATE /', $sql);
                    return true;
                }
            );
            $migration = new \CreateSisRoomFreeMeetings('room-free-meetings');
            $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                new DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
        }
    }

    private function connection(string $slotRoomType = 'VARCHAR(26)'): Sqlite
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo = $connection->pdo;
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('CREATE TABLE rooms (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec("INSERT INTO rooms VALUES ('room-one')");
        $pdo->exec('CREATE TABLE sis_bookings (id VARCHAR(26) PRIMARY KEY, room_id VARCHAR(26) NOT NULL '
            . 'REFERENCES rooms(id), title TEXT NOT NULL, custom_field TEXT)');
        $pdo->exec('CREATE TABLE sis_booking_slots (id VARCHAR(26) PRIMARY KEY, room_id ' . $slotRoomType
            . ' NOT NULL REFERENCES rooms(id), booking_id VARCHAR(26) NOT NULL REFERENCES sis_bookings(id),'
            . ' starts_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE sis_booking_replacements (id VARCHAR(26) PRIMARY KEY REFERENCES sis_booking_slots(id),'
            . ' booking_id VARCHAR(26) NOT NULL REFERENCES sis_bookings(id))');
        $pdo->exec("INSERT INTO sis_bookings VALUES"
            . " ('booking-one', 'room-one', 'Fictional meeting', 'Keep this value')");
        $pdo->exec("INSERT INTO sis_booking_slots VALUES"
            . " ('slot-one', 'room-one', 'booking-one', '2026-08-10 10:00:00')");
        $pdo->exec("INSERT INTO sis_booking_replacements VALUES ('slot-one', 'booking-one')");
        $pdo->exec('CREATE INDEX fixture_slot_window ON sis_booking_slots (room_id, starts_at, id)');
        $pdo->exec('CREATE VIEW booking_places AS SELECT b.id, b.title, r.id AS place FROM sis_bookings b '
            . 'JOIN rooms r ON r.id = b.room_id');
        $pdo->exec('CREATE TABLE trigger_calls (message TEXT)');
        $pdo->exec("CREATE TRIGGER booking_changed AFTER UPDATE OF title ON sis_bookings "
            . "BEGIN INSERT INTO trigger_calls VALUES ('changed'); END");
        return $connection;
    }

    private function migration(Connection $connection): \CreateSisRoomFreeMeetings
    {
        $migration = new \CreateSisRoomFreeMeetings('room-free-meetings');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($connection, 'migration')]));
        return $migration;
    }

    public function testSqliteRetainsEveryValueReferenceIndexViewAndTriggerWithoutFiringItDuringConversion(): void
    {
        $connection = $this->connection();
        $pdo = $connection->pdo;
        $before = [];
        foreach (['sis_bookings', 'sis_booking_slots', 'sis_booking_replacements', 'booking_places'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }
        $migration = $this->migration($connection);
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        }
        self::assertSame(1, $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(0, $pdo->query('PRAGMA legacy_alter_table')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame([], $pdo->query('SELECT * FROM trigger_calls')->fetchAll());
        self::assertSame(['room_id', 'starts_at', 'id'], array_column(
            $pdo->query('PRAGMA index_info(fixture_slot_window)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        ));
        $pdo->exec("INSERT INTO sis_bookings VALUES ('online-one', NULL, 'Fictional online meeting', NULL)");
        $pdo->exec("INSERT INTO sis_booking_slots VALUES ('online-slot', NULL, 'online-one', '2026-08-10 10:00:00')");
        $pdo->exec("UPDATE sis_bookings SET title = 'Fictional updated meeting' WHERE id = 'booking-one'");
        self::assertCount(1, $pdo->query('SELECT * FROM trigger_calls')->fetchAll());
        $migration->up(); // Already-nullable schemas do not rebuild or fire triggers again.
        self::assertCount(1, $pdo->query('SELECT * FROM trigger_calls')->fetchAll());
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function testAnUnsupportedCustomizedColumnRollsBackTheWholeSqliteConversionAndRestoresPragmas(): void
    {
        $connection = $this->connection('BLOB');
        $pdo = $connection->pdo;
        $before = $pdo->query('SELECT * FROM sis_bookings')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->migration($connection)->up();
            self::fail('An unrecognized custom column was silently rewritten.');
        } catch (\LogicException) {
            self::assertSame($before, $pdo->query('SELECT * FROM sis_bookings')->fetchAll(PDO::FETCH_ASSOC));
            $columns = $pdo->query('PRAGMA table_info(sis_bookings)')->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame(1, $columns[1]['notnull']);
            self::assertSame(1, $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            self::assertSame(0, $pdo->query('PRAGMA legacy_alter_table')->fetchColumn());
            self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        }
    }
}
