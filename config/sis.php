<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    'newsletter_enabled' => env(key: 'SIS_NEWSLETTER_ENABLED', default: false) === true,
    'newsletter_hosts' => array_values(array_filter(array_map(
        static fn(string $host): string => strtolower(trim($host)),
        explode(',', (string) env(key: 'SIS_NEWSLETTER_HOSTS', default: ''))
    ))),
    // Explicitly opt in to live delivery. Debug mail files are prohibited for SIS messages.
    'mail_enabled' => env(key: 'SIS_MAIL_ENABLED', default: false) === true,
    'mail_from' => env(key: 'SIS_MAIL_FROM', default: ''),
];
