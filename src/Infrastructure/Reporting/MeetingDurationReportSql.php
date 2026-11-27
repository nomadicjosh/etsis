<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Infrastructure\Persistence\SqlRecords;
use LogicException;

/** Elapsed booked time, from canonical UTC strings; never an instructional/contact-hour calculation. */
final readonly class MeetingDurationReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function minutes(string $alias): string
    {
        $q = $this->sql->quote(...);
        $start = $q($alias) . '.' . $q('starts_at');
        $end = $q($alias) . '.' . $q('ends_at');
        $seconds = match ($this->sql->driver()) {
            'mysql' => 'TIMESTAMPDIFF(SECOND, CAST(' . $start . ' AS DATETIME), CAST(' . $end . ' AS DATETIME))',
            'pgsql' => 'EXTRACT(EPOCH FROM (CAST(' . $end . ' AS TIMESTAMP) - CAST(' . $start . ' AS TIMESTAMP)))',
            'sqlite' => '(CAST(STRFTIME(\'%s\', ' . $end . ') AS INTEGER) - CAST(STRFTIME(\'%s\', '
            . $start . ') AS INTEGER))',
            'sqlsrv' => 'DATEDIFF_BIG(SECOND, CONVERT(DATETIME2, ' . $start . ', 120), CONVERT(DATETIME2, '
            . $end . ', 120))',
            'oci' => 'ROUND((TO_DATE(' . $end . ", 'FXYYYY-MM-DD HH24:MI:SS') - TO_DATE("
            . $start . ", 'FXYYYY-MM-DD HH24:MI:SS')) * 86400)",
            default => throw new LogicException('Meeting duration is unavailable for this database driver.'),
        };
        // Missing/unmatched or nonpositive intervals are unknown, not zero-minute meetings.
        // Integer-second subtraction avoids Julian-day floating point cancellation on SQLite.
        return '(CASE WHEN ' . $end . ' > ' . $start . ' THEN CAST(' . $seconds
        . ' AS DECIMAL(24, 6)) / 60.0 ELSE NULL END)';
    }
}
