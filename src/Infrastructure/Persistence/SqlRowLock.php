<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use InvalidArgumentException;

/** Identifiers are already quoted by SqlRecords. Lock reads must not establish an old snapshot. */
final class SqlRowLock
{
    public static function select(string $table, string $key, string $driver, bool $allColumns = false): string
    {
        $columns = $allColumns ? '*' : $key;
        return match ($driver) {
            'mysql', 'pgsql', 'oci' => "SELECT $columns FROM $table WHERE $key = ? FOR UPDATE",
            'sqlsrv' => "SELECT $columns FROM $table WITH (UPDLOCK, ROWLOCK) WHERE $key = ?",
            'sqlite' => "SELECT $columns FROM $table WHERE $key = ?",
            default => throw new InvalidArgumentException('Unsupported database lock dialect.'),
        };
    }
}
