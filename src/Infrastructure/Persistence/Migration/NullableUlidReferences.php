<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Migration;

use LogicException;
use PDO;
use PDOStatement;
use Qubus\Expressive\Connection;
use Throwable;

/** Shared bounded conversion for nullable ULID references, preserving recorded values and schema objects. */
final class NullableUlidReferences
{
    /** @param non-empty-list<string> $names
     * @param list<string> $dependents
     */
    public static function apply(Connection $connection, string $columnName, array $names, array $dependents = []): void
    {
        if (
            $names === [] || preg_match('/^[a-z][a-z_]+$/D', $columnName) !== 1
            || count($names) > 10 || count($dependents) > 10
        ) {
            throw new LogicException('A bounded set of reference tables is required.');
        }
        foreach ([...$names, ...$dependents] as $name) {
            if (preg_match('/^sis_[a-z_]+$/D', $name) !== 1) {
                throw new LogicException('Use known SIS schema identifiers.');
            }
        }
        $driver = $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            self::sqlite($connection->pdo, $columnName, $names, $dependents);
            return;
        }
        foreach ($names as $name) {
            $table = $connection->quoteIdentifier($name);
            $column = $connection->quoteIdentifier($columnName);
            if ($driver === 'oci') {
                $query = $connection->pdo->prepare(
                    'SELECT NULLABLE FROM USER_TAB_COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $query->execute([$name, $columnName]);
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

    /** Preserve the original SQLite schema, data, indexes and triggers without editing sqlite_schema.
     * @param non-empty-list<string> $names
     * @param list<string> $dependents
     */
    private static function sqlite(PDO $pdo, string $columnName, array $names, array $dependents): void
    {
        $tables = [];
        foreach ($names as $name) {
            $found = false;
            foreach (self::query($pdo, 'PRAGMA table_info("' . $name . '")')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ($column['name'] === $columnName) {
                    $found = true;
                    if ((int) $column['notnull'] === 1) {
                        $tables[] = $name;
                    }
                }
            }
            if (!$found) {
                throw new LogicException('The expected reference column is missing.');
            }
        }
        if ($tables === []) {
            return;
        }
        if ($pdo->inTransaction()) {
            throw new LogicException('Run this SQLite schema conversion outside a wrapping transaction.');
        }
        $foreignKeys = (int) self::query($pdo, 'PRAGMA foreign_keys')->fetchColumn();
        $legacy = (int) self::query($pdo, 'PRAGMA legacy_alter_table')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $started = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
            $query = $pdo->prepare("SELECT tbl_name, sql FROM sqlite_schema WHERE tbl_name IN ("
                . implode(', ', array_fill(0, count($names), '?')) . ")"
                . " AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name LIMIT 201");
            $query->execute($names);
            $objects = $query->fetchAll(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if (count($objects) > 200) {
                throw new LogicException('Review the extended reference schema before converting it.');
            }
            foreach ($tables as $name) {
                $query = $pdo->prepare('SELECT sql FROM sqlite_schema WHERE type = ? AND name = ?');
                $query->execute(['table', $name]);
                $original = $query->fetchColumn();
                $query->closeCursor();
                if (!is_string($original)) {
                    throw new LogicException('The original reference schema is unavailable.');
                }
                $temporary = $name . '_nullable_' . $columnName;
                $ddl = preg_replace(
                    '/^CREATE\s+TABLE\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)/i',
                    'CREATE TABLE "' . $temporary . '"',
                    $original,
                    1,
                    $tableChanges
                );
                $ddl = preg_replace('/((?:"' . $columnName . '"|`' . $columnName . '`|'
                    . '\[' . $columnName . '\]|' . $columnName . ')\s+(?:VARCHAR|CHAR|TEXT)'
                    . '(?:\s*\(\s*26\s*\))?\s*)NOT\s+NULL\b/i', '$1', $ddl ?? '', -1, $columnChanges);
                if ($tableChanges !== 1 || $columnChanges !== 1 || $ddl === null) {
                    throw new LogicException('Review the customized reference-column definition before converting it.');
                }
                $before = self::digest($pdo, $name);
                $pdo->exec($ddl);
                $pdo->exec('INSERT INTO "' . $temporary . '" SELECT * FROM "' . $name . '"');
                if ($before !== self::digest($pdo, $temporary)) {
                    throw new LogicException('The reference copy did not preserve every recorded value.');
                }
                $pdo->exec('DROP TABLE "' . $name . '"');
                $pdo->exec('ALTER TABLE "' . $temporary . '" RENAME TO "' . $name . '"');
            }
            foreach ($objects as $object) {
                if (in_array($object['tbl_name'], $tables, true)) {
                    $pdo->exec($object['sql']);
                }
            }
            foreach (array_unique([...$names, ...$dependents]) as $name) {
                if (self::query($pdo, 'PRAGMA foreign_key_check("' . $name . '")')->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new LogicException('The converted references need review.');
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
    private static function digest(PDO $pdo, string $name): string
    {
        $query = self::query($pdo, 'SELECT * FROM "' . $name . '" ORDER BY "id" LIMIT 100001');
        $hash = hash_init('sha256');
        $rows = 0;
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (++$rows > 100000) {
                throw new LogicException(
                    'This SQLite reference dataset needs a separately bounded offline conversion.'
                );
            }
            hash_update($hash, serialize($row));
        }
        return hash_final($hash);
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new LogicException('Unable to inspect the reference schema.');
        }
        return $statement;
    }
}
