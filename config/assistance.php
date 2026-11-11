<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    "enabled" => env(key: "SIS_AI_ENABLED", default: false) === true,
    // Institutional confirmation of a no-training agreement or institution-operated service.
    "data_policy_approved" => env(key: "SIS_AI_DATA_POLICY_APPROVED", default: false) === true,
    "label" => env(key: "SIS_AI_LABEL", default: "Institutional AI service"),
    "endpoint" => env(key: "SIS_AI_ENDPOINT", default: "https://api.openai.com/v1"),
    "protocol" => env(key: "SIS_AI_PROTOCOL", default: "responses"),
    "auth" => env(key: "SIS_AI_AUTH", default: "bearer"),
    "key" => env(key: "SIS_AI_KEY", default: ""),
    "model" => env(key: "SIS_AI_MODEL", default: ""),
    // Explicit opt-in for institution-operated local/private endpoints. HTTPS remains the default.
    "allow_http" => env(key: "SIS_AI_ALLOW_HTTP", default: false) === true,
];
