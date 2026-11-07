<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    'path' => env(key: 'COOKIE_PATH', default: '/'),
    'domain' => env(key: 'COOKIE_DOMAIN', default: ''),
    // Authentication envelopes require positive lifetimes (seconds).
    'lifetime' => 3600,
    'remember' => 2592000,
    'secure' => env(key: 'COOKIE_SECURE', default: true),
    'samesite' => env(key: 'COOKIE_SAMESITE', default: 'lax'),
];
