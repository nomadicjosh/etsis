<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\Repository\RelationalReportSource;
use Domain\Reporting\ValueObject\ReportQuery;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRelationalReportSource implements RelationalReportSource
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function datasets(): array
    {
        return AcademicReportSchema::datasets();
    }
    /** A capture calls this once inside its consistent read transaction, before paging.
     * Ordinary live pages call it independently. */
    public function assertReady(ReportQuery $query): void
    {
        if ($query->schema['financial_balances'] ?? false) {
            $this->requireCurrentBalances();
        }
        if ($query->schema['financial_entries'] ?? false) {
            $this->requireCurrentEntries();
        }
    }

    /** Check the atomic posting projection checkpoint, without scanning or decrypting individual events. */
    private function requireCurrentEntries(): void
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $alias, string $field): string => $q($alias) . '.' . $q($field);
        $missing = $this->sql->fetch(
            'SELECT ' . $c('a', 'id') . ' FROM ' . $q('sis_student_accounts') . ' a'
            . ' LEFT JOIN ' . $q('sis_account_entry_heads') . ' p ON ' . $c('p', 'id') . ' = ' . $c('a', 'id')
            . ' LEFT JOIN ' . $q('sis_history_streams') . ' h ON ' . $c('h', 'aggregate_id') . ' = ' . $c('a', 'id')
            . ' AND ' . $c('h', 'stream_type') . ' = ? WHERE ' . $c('p', 'id') . ' IS NULL OR '
            . $c('h', 'id') . ' IS NULL OR ' . $c('p', 'position') . ' <> ' . $c('h', 'version')
            . ' OR ' . $c('p', 'entry_count') . ' < 0',
            ['student_account'],
            1
        );
        if ($missing !== []) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'report',
                'message' => 'Financial posting reports need verification. No partial report was returned. '
                    . 'Ask a system administrator to review the posting projection.']]);
        }
    }

    /** One bounded indexed consistency probe, with no payload decryption, replay or write locks. */
    private function requireCurrentBalances(): void
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $alias, string $column): string => $q($alias) . '.' . $q($column);
        $missing = $this->sql->fetch(
            'SELECT ' . $c('a', 'id') . ' FROM ' . $q('sis_student_accounts') . ' a'
            . ' LEFT JOIN ' . $q('sis_account_balances') . ' b ON ' . $c('b', 'id') . ' = ' . $c('a', 'id')
            . ' LEFT JOIN ' . $q('sis_history_streams') . ' h ON ' . $c('h', 'aggregate_id') . ' = ' . $c('a', 'id')
            . ' AND ' . $c('h', 'stream_type') . ' = ? WHERE ' . $c('b', 'id') . ' IS NULL OR '
            . $c('h', 'id') . ' IS NULL OR ' . $c('b', 'position') . ' <> ' . $c('h', 'version')
            . ' OR ' . $c('b', 'charges_minor') . ' IS NULL OR ' . $c('b', 'payments_minor') . ' IS NULL'
            . ' OR ' . $c('b', 'refunds_minor') . ' IS NULL OR ' . $c('b', 'charges_minor') . ' < 0'
            . ' OR ' . $c('b', 'payments_minor') . ' < 0 OR ' . $c('b', 'refunds_minor') . ' < 0'
            . ' OR ' . $c('b', 'balance_minor') . ' <> (' . $c('b', 'charges_minor') . ' - '
            . $c('b', 'payments_minor') . ' + ' . $c('b', 'refunds_minor') . ')',
            ['student_account'],
            1
        );
        if ($missing !== []) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'report',
                'message' => 'Financial report balances need verification. No partial report was returned. '
                    . 'Ask a system administrator to review the account reporting projection.']]);
        }
    }
    public function execute(#[\SensitiveParameter] ReportQuery $query, int $offset): array
    {
        return $this->boundedPage($query, $offset, 51);
    }
    /** Internal capture reader; smaller batches keep wide text rows bounded in buffered PDO drivers.
     * @return list<array<string,mixed>>
     */
    public function boundedPage(
        #[\SensitiveParameter] ReportQuery $query,
        int $offset,
        int $limit,
        bool $checkSource = true,
    ): array {
        if ($limit < 1 || $limit > 51) {
            throw new \InvalidArgumentException('Use a bounded report page.');
        }
        if ($checkSource) {
            $this->assertReady($query);
        }
        $plan = new ReportSelectCompiler($this->sql)->compile($query);
        $rows = $this->sql->fetchPage($plan["sql"], $plan["parameters"], $limit, $offset);
        foreach ($rows as &$row) {
            foreach (array_keys($row) as $key) {
                if (str_starts_with($key, "rowkey") || $key === "report_page_row") {
                    unset($row[$key]);
                }
            }
            foreach ($query->definition["columns"] as $i => $column) {
                $key = "c" . $i;
                if ($row[$key] === null) {
                    continue;
                }
                $row[$key] = match ($query->outputType($i)) {
                    "integer" => (int) $row[$key],
                    "double" => (float) $row[$key],
                    "boolean" => (bool) $row[$key],
                    default => $row[$key],
                };
            }
        }
        unset($row);
        return $rows;
    }
}
