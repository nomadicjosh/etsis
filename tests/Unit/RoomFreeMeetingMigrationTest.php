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
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000015_AddSisVirtualPublicEvents.php';
    }

    public function testNativeNullableStatementsKeepTheFrameworkColumnTypes(): void
    {
        foreach (
            ['mysql' => 'MODIFY COLUMN "room_id" VARCHAR(26) NULL',
            'pgsql' => 'ALTER COLUMN "room_id" DROP NOT NULL',
            'sqlsrv' => 'ALTER COLUMN "room_id" NVARCHAR(26) NULL',
            'oci' => 'MODIFY ("room_id" NULL)'] as $driver => $statement
        ) {
            foreach (
                [\CreateSisRoomFreeMeetings::class => ['sis_bookings', 'sis_booking_slots'],
                \AddSisVirtualPublicEvents::class => ['sis_public_events']] as $class => $tables
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
                $connection->method('quoteIdentifier')->willReturnCallback(
                    static fn(string $s): string => '"' . $s . '"'
                );
                $seen = [];
                $connection->expects(self::exactly(count($tables)))->method('command')->willReturnCallback(
                    static function (string $sql) use ($statement, &$seen): bool {
                        self::assertStringContainsString($statement, $sql);
                        self::assertDoesNotMatchRegularExpression('/DROP TABLE|DELETE FROM|INSERT INTO|UPDATE /', $sql);
                        $seen[] = $sql;
                        return true;
                    }
                );
                $migration = new $class('room-free-meetings');
                $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
                    new DbalMigrationAdapter($connection, 'migration')]));
                $migration->up();
                foreach ($tables as $index => $table) {
                    self::assertStringStartsWith('ALTER TABLE "' . $table . '" ', $seen[$index]);
                }
            }
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

    public function testPublicRoomConversionPreservesContentInboundReferencesViewsIndexesAndTriggers(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo = $connection->pdo;
        $pdo->exec('CREATE TABLE sis_rooms (id VARCHAR(26) PRIMARY KEY)');
        $pdo->exec("INSERT INTO sis_rooms VALUES ('fictional-room')");
        $pdo->exec('CREATE TABLE sis_public_events (id VARCHAR(26) PRIMARY KEY,'
            . ' room_id VARCHAR(26) NOT NULL REFERENCES sis_rooms(id), title TEXT NOT NULL, custom_field TEXT)');
        $pdo->exec('CREATE TABLE sis_public_event_times (id VARCHAR(26) PRIMARY KEY,'
            . ' event_id VARCHAR(26) NOT NULL REFERENCES sis_public_events(id), starts_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO sis_public_events VALUES"
            . " ('fictional-event', 'fictional-room', 'Public title', 'Keep me')");
        $pdo->exec("INSERT INTO sis_public_event_times VALUES ('fictional-time', 'fictional-event', '2026-08-10')");
        $pdo->exec('CREATE INDEX fixture_public_place ON sis_public_events (room_id, id)');
        $pdo->exec('CREATE VIEW public_places AS SELECT id, room_id, title FROM sis_public_events');
        $pdo->exec('CREATE TABLE fixture_calls (message TEXT)');
        $pdo->exec("CREATE TRIGGER public_content_updated AFTER UPDATE OF title ON sis_public_events "
            . "BEGIN INSERT INTO fixture_calls VALUES ('changed'); END");
        $before = [];
        foreach (['sis_public_events', 'sis_public_event_times', 'public_places'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }
        $migration = new \AddSisVirtualPublicEvents('virtual-public-events');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($connection, 'migration')]));
        $migration->up();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        }
        self::assertSame(1, $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(0, $pdo->query('PRAGMA legacy_alter_table')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame([], $pdo->query('SELECT * FROM fixture_calls')->fetchAll());
        self::assertSame(['room_id', 'id'], array_column(
            $pdo->query('PRAGMA index_info(fixture_public_place)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        ));
        $pdo->exec("INSERT INTO sis_public_events VALUES ('online-event', NULL, 'Online invitation', NULL)");
        $pdo->exec("INSERT INTO sis_public_event_times VALUES ('online-time', 'online-event', '2026-08-11')");
        $migration->up();
        self::assertSame([], $pdo->query('SELECT * FROM fixture_calls')->fetchAll());
        $pdo->exec("UPDATE sis_public_events SET title = 'Updated title' WHERE id = 'fictional-event'");
        self::assertCount(1, $pdo->query('SELECT * FROM fixture_calls')->fetchAll());
    }

    public function testMissingPublicSourceSchemaCannotBeMarkedSuccessfullyConverted(): void
    {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $migration = new \AddSisVirtualPublicEvents('virtual-public-events');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new DbalMigrationAdapter($connection, 'migration')]));
        $this->expectException(\LogicException::class);
        $migration->up();
    }
}
