<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\Repository\ReportCaptureSource;
use Domain\Reporting\ValueObject\ReportCapture;
use Domain\Reporting\ValueObject\ReportQuery;
use Qubus\Expressive\Database;

/** Static captures and background exports share the consistent database traversal. */
final readonly class SqlReportCaptureSource implements ReportCaptureSource
{
    public function __construct(private Database $db, private SqlRelationalReportSource $source)
    {
    }

    public function capture(#[\SensitiveParameter] ReportQuery $query): ReportCapture
    {
        $rows = [];
        new SqlReportResultReader($this->db, $this->source)->read(
            $query,
            static function (array $page) use (&$rows): void {
                array_push($rows, ...$page);
            },
            ReportCapture::MAX_ROWS,
            ReportCapture::MAX_BYTES,
            15,
            pageSize: 10,
        );
        return new ReportCapture(array_column($query->definition['columns'], 'label'), $rows);
    }
}
