<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Shared\Exception\ValidationException;
use Infrastructure\Persistence\SqlRecords;

use function Codefy\Framework\Helpers\trans;

/** Indexed completeness/source checks only; ordinary reports never read protected academic payloads. */
final readonly class AcademicDateReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function requireReady(): void
    {
        if (!$this->sql->tableExists('sis_academic_date_index')) {
            self::unavailable();
        }
        $q = $this->sql->quote(...);
        $c = static fn(string $a, string $f): string => $q($a) . '.' . $q($f);
        $missing = $this->sql->fetch(
            'SELECT ' . $c('c', 'id') . ' FROM ' . $q('sis_academic_credits') . ' c' .
            ' LEFT JOIN ' . $q('sis_history_streams') . ' h ON ' . $c('h', 'aggregate_id') . ' = ' . $c('c', 'id') .
            ' AND ' . $c('h', 'stream_type') . ' = ? LEFT JOIN ' . $q('sis_academic_credit_streams') .
            ' m ON ' . $c('m', 'id') . ' = ' . $c('c', 'id') . ' LEFT JOIN ' . $q('sis_academic_date_index') .
            ' d ON ' . $c('d', 'id') . ' = ' . $c('c', 'id') . ' WHERE ' . $c('c', 'credit_type') . ' = ? AND (' .
            $c('h', 'id') . ' IS NOT NULL OR ' . $c('m', 'id') . ' IS NOT NULL OR ' . $c('d', 'id') .
            ' IS NOT NULL) AND (' . $c('h', 'id') . ' IS NULL OR ' . $c('m', 'id') . ' IS NULL OR ' .
            $c('d', 'id') . ' IS NULL OR ' . $c('d', 'position') . ' <> ' . $c('h', 'version') . ' OR ' .
            $c('d', 'head_hash') . ' <> ' . $c('h', 'head_hash') . ' OR ' . $c('d', 'student_id') . ' <> ' .
            $c('c', 'student_id') . ' OR ' . $c('d', 'enrollment_id') . ' <> ' . $c('c', 'source_id') .
            ' OR ' . $c('m', 'student_id') . ' <> ' . $c('c', 'student_id') . ' OR ' . $c('m', 'enrollment_id') .
            ' <> ' . $c('c', 'source_id') . ' OR ' . $c('d', 'removed') . ' <> 0)',
            ['academic_credit', 'institutional'],
            1
        );
        if ($missing !== []) {
            self::unavailable();
        }
    }

    private static function unavailable(): never
    {
        throw new ValidationException([['field' => 'report', 'message' => trans(
            'Institutional academic dates need reconciliation before this report can run. '
            . 'No partial result was returned. Ask a system administrator to verify the academic date index.',
        )]]);
    }
}
