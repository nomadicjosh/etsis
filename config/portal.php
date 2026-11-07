<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    'name' => env('PORTAL_NAME', 'etsis'),
    'theme' => env('PORTAL_THEME', 'campus-harbor'),
    'tagline' => 'Your next chapter starts here.',
    'payments' => [
        'enabled' => env('PORTAL_PAYMENTS_ENABLED', false),
        'live' => env('PORTAL_PAYMENTS_LIVE', false),
        'secret' => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],
];
