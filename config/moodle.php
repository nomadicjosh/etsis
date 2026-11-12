<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    "enabled" => env(key: "SIS_MOODLE_ENABLED", default: false) === true,
    "url" => env(key: "SIS_MOODLE_URL", default: ""),
    "token" => env(key: "SIS_MOODLE_TOKEN", default: ""),
    // Optional trusted CA bundle path. Verification cannot be disabled.
    "ca_file" => env(key: "SIS_MOODLE_CA_FILE", default: ""),
];
