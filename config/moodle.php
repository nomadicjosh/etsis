<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    "course_creation_enabled" => env(key: "SIS_MOODLE_COURSE_CREATION_ENABLED", default: false) === true,
    "automatic_accounts_enabled" => env(key: "SIS_MOODLE_AUTOMATIC_ACCOUNTS_ENABLED", default: false) === true,
    "automatic_sign_in" => env(key: "SIS_MOODLE_AUTOMATIC_SIGN_IN", default: "password_email"),
    "section_sync_enabled" => env(key: "SIS_MOODLE_SECTION_SYNC_ENABLED", default: false) === true,
    "sync_actor" => env(key: "SIS_MOODLE_SYNC_ACTOR", default: ""),
    "access_before_days" => (int) env(key: "SIS_MOODLE_ACCESS_BEFORE_DAYS", default: 0),
    "access_after_days" => (int) env(key: "SIS_MOODLE_ACCESS_AFTER_DAYS", default: 0),
    "launch_enabled" => env(key: "SIS_MOODLE_LAUNCH_ENABLED", default: false) === true,
    "launch_secret" => env(key: "SIS_MOODLE_LAUNCH_SECRET", default: ""),
    "enabled" => env(key: "SIS_MOODLE_ENABLED", default: false) === true,
    // Request intake only. No external account worker is enabled by this setting.
    "account_requests_enabled" => env(key: "SIS_MOODLE_ACCOUNT_REQUESTS_ENABLED", default: false) === true,
    "provisioning_enabled" => env(key: "SIS_MOODLE_PROVISIONING_ENABLED", default: false) === true,
    "url" => env(key: "SIS_MOODLE_URL", default: ""),
    "token" => env(key: "SIS_MOODLE_TOKEN", default: ""),
    // Optional trusted CA bundle path. Verification cannot be disabled.
    "ca_file" => env(key: "SIS_MOODLE_CA_FILE", default: ""),
];
