<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    // Explicitly opt in to live delivery. Debug mail files are prohibited for SIS messages.
    'mail_enabled' => env(key: 'SIS_MAIL_ENABLED', default: false) === true,
    'mail_from' => env(key: 'SIS_MAIL_FROM', default: ''),
];
