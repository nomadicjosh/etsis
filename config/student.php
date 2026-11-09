<?php

declare(strict_types=1);

return [
    // Proposals only: staff review and confirm each course/activity change before anything is applied.
    'hiatus_proposals' => [
        'W' => ['deactivate' => true, 'courses' => true],
        'LOA' => ['deactivate' => false, 'courses' => false],
        'SA' => ['deactivate' => false, 'courses' => false],
        'ILLN' => ['deactivate' => false, 'courses' => false],
        'DISM' => ['deactivate' => false, 'courses' => false],
    ],
];
