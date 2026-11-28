<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    "enabled" => env("SIS_PERSON_PHOTOS_ENABLED", false) === true,
    "scanner_reviewed" =>
        env("SIS_PERSON_PHOTO_SCANNER_REVIEWED", false) === true,
    "retention_days" => (int) env("SIS_PERSON_PHOTO_RETENTION_DAYS", 0),
    "gravatar_enabled" => env("SIS_PERSON_GRAVATAR_ENABLED", false) === true,
];
