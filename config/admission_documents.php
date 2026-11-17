<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    'enabled' => env('SIS_ADMISSION_DOCUMENTS_ENABLED', false) === true,
    // Set deliberately under the institution's retention policy before enabling uploads.
    'scanner_reviewed' => env('SIS_ADMISSION_DOCUMENT_SCANNER_REVIEWED', false) === true,
    'retention_days' => (int) env('SIS_ADMISSION_DOCUMENT_RETENTION_DAYS', 0),
    'scanner_binary' => env('SIS_ADMISSION_DOCUMENT_SCANNER', '/usr/bin/clamdscan'),
];
