<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Reporting\ValueObject\ReportCapture;
use Domain\Reporting\ValueObject\ReportQuery;

interface ReportCaptureSource
{
    /** A complete bounded capture from one consistent read; no partial result may be returned. */
    public function capture(#[\SensitiveParameter] ReportQuery $query): ReportCapture;
}
