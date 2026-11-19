<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Reporting\ValueObject\ReportExportArtifact;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\ValueObject\EntityId;

interface ReportExportCapture
{
    /** Called outside a write transaction. Static state selects existing protected results.
     * @param array<string,mixed>|null $staticState */
    public function capture(
        EntityId $job,
        EntityId $report,
        #[\SensitiveParameter] ReportQuery $query,
        #[\SensitiveParameter] ?array $staticState,
    ): ReportExportArtifact;
}
