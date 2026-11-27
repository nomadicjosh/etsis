<?php

declare(strict_types=1);

$websiteNotices = [];
foreach (['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'] as $tone) {
    $websiteNotices[$tone] = [
        'slug' => 'alert',
        'settings' => ['attributes' => ['alert-context' => 'alert-' . $tone,
            'alert-label' => 'Fictional ' . $tone . ' notice', 'alert-dismissible' => '1']],
        'blocks' => ['alert-text' => ['html' => '<p>Retained fictional ' . $tone . ' body. '
            . '<a href="#page-title">Read ' . $tone . ' information</a></p>']],
    ];
}
$websiteNotices['earlier'] = ['slug' => 'alert', 'settings' => ['attributes' => ['alert-context' => 'alert-warning']],
    'blocks' => ['alert-text' => ['html' => '<p>Retained earlier notice body.</p>']]];
if (($_GET['hostile'] ?? '') === '1') {
    $hostileLabel = 'Fictional <img src=x onerror=alert(1)> & notice';
    $websiteNotices['primary']['settings']['attributes']['alert-label'] = $hostileLabel;
    $websiteNotices['primary']['settings']['attributes']['alert-context'] = 'alert-primary" onclick="alert(1)';
}
