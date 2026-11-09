<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Reporting\ValueObject\ReportQuery;

interface RelationalReportSource
{
    /** @return array<string, array<string, mixed>> */
    public function datasets(): array;
    /** @return list<array<string, mixed>> */
    public function execute(#[\SensitiveParameter] ReportQuery $query, int $offset): array;
}
