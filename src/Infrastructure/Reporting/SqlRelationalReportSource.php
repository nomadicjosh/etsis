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
    public function execute(#[\SensitiveParameter] ReportQuery $query, int $offset): array
    {
        return $this->boundedPage($query, $offset, 51);
    }
    /** Internal capture reader; smaller batches keep wide text rows bounded in buffered PDO drivers.
     * @return list<array<string,mixed>>
     */
    public function boundedPage(#[\SensitiveParameter] ReportQuery $query, int $offset, int $limit): array
    {
        if ($limit < 1 || $limit > 51) {
            throw new \InvalidArgumentException('Use a bounded report page.');
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
