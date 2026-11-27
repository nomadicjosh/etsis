<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\Enum\ReportDateBucket;
use InvalidArgumentException;

/** Only server-owned expressions and validated buckets reach this compiler. */
final class ReportCalendarSql
{
    public static function bucket(
        string $expression,
        string $type,
        ReportDateBucket $bucket,
        string $driver,
        bool $nativeDate = true,
    ): string {
        if (!in_array($type, ["date", "datetime"], true)) {
            throw new InvalidArgumentException("Calendar grouping requires a date field.");
        }
        if (!in_array($driver, ["mysql", "pgsql", "sqlite", "sqlsrv", "oci"], true)) {
            throw new InvalidArgumentException("Calendar grouping is unavailable for this database driver.");
        }
        // Metadata distinguishes native DATE columns from canonical ISO date/datetime strings.
        // Explicit native-date formatting avoids server locale and session DateStyle dependencies.
        if ($type === "date" && $nativeDate) {
            $expression = match ($driver) {
                "mysql" => "DATE_FORMAT(" . $expression . ", '%Y-%m-%d')",
                "pgsql", "oci" => "TO_CHAR(" . $expression . ", 'YYYY-MM-DD')",
                "sqlsrv" => "CONVERT(VARCHAR(10), " . $expression . ", 23)",
                default => $expression,
            };
        }
        $function = $driver === "sqlsrv" ? "SUBSTRING" : "SUBSTR";
        return $function . "(" . $expression . ", 1, " . $bucket->length() . ")";
    }
}
