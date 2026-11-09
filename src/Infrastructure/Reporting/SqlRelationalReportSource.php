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
    public function execute(ReportQuery $query, int $offset): array
    {
        $plan = new ReportSelectCompiler($this->sql)->compile($query);
        $rows = $this->sql->fetchPage($plan["sql"], $plan["parameters"], 51, $offset);
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
