<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class CreateSisRoomFreeMeetings extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $connection = $adapter->connection();
        $driver = $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->sqlite($connection->pdo);
            return;
        }
        foreach (['sis_bookings', 'sis_booking_slots'] as $name) {
            $table = $connection->quoteIdentifier($name);
            $column = $connection->quoteIdentifier('room_id');
            if ($driver === 'oci') {
                $query = $connection->pdo->prepare(
                    'SELECT NULLABLE FROM USER_TAB_COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $query->execute([$name, 'room_id']);
                $nullable = $query->fetchColumn();
                $query->closeCursor();
                if ($nullable === 'Y') {
                    continue;
                }
            }
            $change = match ($driver) {
                'mysql' => ' MODIFY COLUMN ' . $column . ' VARCHAR(26) NULL',
                'pgsql' => ' ALTER COLUMN ' . $column . ' DROP NOT NULL',
                'sqlsrv', 'dblib' => ' ALTER COLUMN ' . $column . ' NVARCHAR(26) NULL',
                'oci' => ' MODIFY (' . $column . ' NULL)',
                default => throw new LogicException('This connection needs an explicit nullable-column dialect.'),
            };
            $connection->command('ALTER TABLE ' . $table . $change);
        }
    }

    /** Preserve the original SQLite schema, data, indexes and triggers without editing sqlite_schema. */
    private function sqlite(PDO $pdo): void
    {
        $tables = [];
        foreach (['sis_bookings', 'sis_booking_slots'] as $name) {
            foreach ($pdo->query('PRAGMA table_info("' . $name . '")')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ($column['name'] === 'room_id' && (int) $column['notnull'] === 1) {
                    $tables[] = $name;
                }
            }
        }
        if ($tables === []) {
            return;
        }
        if ($pdo->inTransaction()) {
            throw new LogicException('Run this SQLite schema conversion outside a wrapping transaction.');
        }
        $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $legacy = (int) $pdo->query('PRAGMA legacy_alter_table')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $started = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
            $objects = $pdo->query("SELECT tbl_name, sql FROM sqlite_schema"
                . " WHERE tbl_name IN ('sis_bookings', 'sis_booking_slots')"
                . " AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name LIMIT 201")
                ->fetchAll(PDO::FETCH_ASSOC);
            if (count($objects) > 200) {
                throw new LogicException('Review the extended booking schema before converting it.');
            }
            foreach ($tables as $name) {
                $query = $pdo->prepare('SELECT sql FROM sqlite_schema WHERE type = ? AND name = ?');
                $query->execute(['table', $name]);
                $original = $query->fetchColumn();
                $query->closeCursor();
                if (!is_string($original)) {
                    throw new LogicException('The original booking schema is unavailable.');
                }
                $temporary = $name . '_nullable_room';
                $ddl = preg_replace(
                    '/^CREATE\s+TABLE\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)/i',
                    'CREATE TABLE "' . $temporary . '"',
                    $original,
                    1,
                    $tableChanges
                );
                $ddl = preg_replace('/((?:"room_id"|`room_id`|\[room_id\]|room_id)\s+(?:VARCHAR|CHAR|TEXT)'
                    . '(?:\s*\(\s*26\s*\))?\s*)NOT\s+NULL\b/i', '$1', $ddl ?? '', -1, $columnChanges);
                if ($tableChanges !== 1 || $columnChanges !== 1 || $ddl === null) {
                    throw new LogicException('Review the customized room-column definition before converting it.');
                }
                $before = $this->digest($pdo, $name);
                $pdo->exec($ddl);
                $pdo->exec('INSERT INTO "' . $temporary . '" SELECT * FROM "' . $name . '"');
                if ($before !== $this->digest($pdo, $temporary)) {
                    throw new LogicException('The booking copy did not preserve every recorded value.');
                }
                $pdo->exec('DROP TABLE "' . $name . '"');
                $pdo->exec('ALTER TABLE "' . $temporary . '" RENAME TO "' . $name . '"');
            }
            foreach ($objects as $object) {
                if (in_array($object['tbl_name'], $tables, true)) {
                    $pdo->exec($object['sql']);
                }
            }
            foreach (['sis_bookings', 'sis_booking_slots', 'sis_booking_replacements'] as $name) {
                if ($pdo->query('PRAGMA foreign_key_check("' . $name . '")')->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new LogicException('The converted booking references need review.');
                }
            }
            $pdo->exec('COMMIT');
            $started = false;
        } catch (Throwable $error) {
            if ($started) {
                $pdo->exec('ROLLBACK');
            }
            throw $error;
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table=' . $legacy);
            $pdo->exec('PRAGMA foreign_keys=' . $foreignKeys);
        }
    }

    /** Bounded, streamed verification; never print the underlying booking data. */
    private function digest(PDO $pdo, string $name): string
    {
        $query = $pdo->query('SELECT * FROM "' . $name . '" ORDER BY "id" LIMIT 100001');
        $hash = hash_init('sha256');
        $rows = 0;
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (++$rows > 100000) {
                throw new LogicException('This SQLite booking dataset needs a separately bounded offline conversion.');
            }
            hash_update($hash, serialize($row));
        }
        return hash_final($hash);
    }

    public function down(): void
    {
        throw new LogicException('Preserve room-free meeting facts; downgrade needs an explicit conversion.');
    }
}
