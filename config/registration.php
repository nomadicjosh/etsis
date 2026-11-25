<?php

declare(strict_types=1);

return [
    // Public self-signup only. Existing sign-in and authorized staff-created accounts remain available.
    'enabled' => true,
    // Exact email domains, without @ or wildcards. An empty list permits any otherwise valid email.
    'email_domains' => [],
];
