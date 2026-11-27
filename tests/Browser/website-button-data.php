<?php

declare(strict_types=1);

$websiteButtons = [
    'action' => ['button-text' => 'Fictional action'],
    'submit' => ['button-text' => 'Submit fictional form', 'button-type' => 'submit'],
    'local' => ['button-text' => 'Explore fictional information', 'button-type' => 'link',
        'button-url' => '#page-title'],
    'external' => ['button-text' => 'Fictional external information', 'button-type' => 'link',
        'button-url' => 'https://example.invalid/programs/?level=ug&subject=arts', 'button-new-tab' => '1'],
    'invalid' => ['button-text' => 'Unavailable fictional destination', 'button-type' => 'link',
        'button-url' => 'javascript:alert(1)'],
    'email' => ['button-text' => 'Email fictional admissions', 'button-type' => 'link',
        'button-url' => 'mailto:admissions@example.invalid', 'button-new-tab' => '1'],
    'hostile' => ['button-text' => 'Fictional <img src=x onerror=alert(1)> & information',
        'button-style' => 'btn-primary" onclick="alert(1)', 'button-type' => 'reset'],
    'long' => ['button-text' => str_repeat('FictionalAdmission', 10), 'button-style' => 'btn-primary',
        'button-size' => 'btn-sm'],
];
foreach (['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'] as $tone) {
    $websiteButtons['tone-' . $tone] = ['button-type' => 'link', 'button-style' => 'btn-' . $tone,
        'button-url' => '#page-title', 'button-text' => 'Fictional ' . $tone . ' information'];
}
