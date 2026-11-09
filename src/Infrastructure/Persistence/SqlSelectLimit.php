<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use InvalidArgumentException;

/** Bounds prepared SELECTs at the server, including PDO drivers that buffer result sets. */
final class SqlSelectLimit
{
    public static function page(string $statement, string $driver, int $limit, int $offset): string
    {
        if ($offset < 0 || $offset > 2_000_000_000) {
            throw new InvalidArgumentException('Use a valid result page offset.');
        }
        if ($offset === 0) {
            return self::apply($statement, $driver, $limit);
        }
        self::apply($statement, $driver, $limit); // Validate the SELECT/limit before constructing the page window.
        return match ($driver) {
            'mysql', 'pgsql', 'sqlite' => $statement . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            'oci' => 'SELECT * FROM (SELECT report_data.*, ROWNUM AS "report_page_row" FROM (' . $statement
            . ') report_data WHERE ROWNUM <= ' . ($offset + $limit) . ') WHERE "report_page_row" > ' . $offset,
            'sqlsrv' => $statement . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY',
            default => throw new InvalidArgumentException('Unsupported driver for paged SELECT.'),
        };
    }

    public static function apply(string $statement, string $driver, int $limit): string
    {
        if ($limit < 1 || $limit > 1000 || preg_match('/\ASELECT\s/i', $statement) !== 1) {
            throw new InvalidArgumentException('A SELECT and a result limit of 1–1000 are required.');
        }

        return match ($driver) {
            'mysql', 'pgsql', 'sqlite' => $statement . ' LIMIT ' . $limit,
            'sqlsrv' => preg_replace('/\ASELECT(\s+DISTINCT)?\s+/i', 'SELECT$1 TOP (' . $limit . ') ', $statement, 1)
            ?? throw new InvalidArgumentException('Invalid bounded SELECT.'),
            'oci' => 'SELECT * FROM (' . $statement . ') WHERE ROWNUM <= ' . $limit,
            default => throw new InvalidArgumentException('Unsupported driver for bounded SELECT.'),
        };
    }
}
