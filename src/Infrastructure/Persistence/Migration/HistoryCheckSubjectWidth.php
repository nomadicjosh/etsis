<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Migration;

use LogicException;
use PDO;
use Qubus\Expressive\Connection;

/** Widen operational routing without converting or rewriting any recorded receipt. */
final class HistoryCheckSubjectWidth
{
    public static function apply(Connection $connection): void
    {
        $statement = self::statement(
            $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            $connection->quoteIdentifier(...),
        );
        if ($statement !== null) {
            $connection->command($statement);
        }
    }

    public static function statement(string $driver, callable $quote): ?string
    {
        // SQLite's text affinity does not enforce VARCHAR lengths. Avoid an unnecessary table rebuild.
        if ($driver === 'sqlite') {
            return null;
        }
        $table = $quote('sis_history_checks');
        $column = $quote('subject_type');
        $change = match ($driver) {
            'mysql' => ' MODIFY COLUMN ' . $column . ' VARCHAR(32) NOT NULL',
            'pgsql' => ' ALTER COLUMN ' . $column . ' TYPE VARCHAR(32)',
            'sqlsrv', 'dblib' => ' ALTER COLUMN ' . $column . ' NVARCHAR(32) NOT NULL',
            'oci' => ' MODIFY (' . $column . ' VARCHAR2(32 CHAR))',
            default => throw new LogicException('This connection needs an explicit string-widening dialect.'),
        };
        return 'ALTER TABLE ' . $table . $change;
    }
}
