<?php

declare(strict_types=1);

use Application\View\WebsiteButton;

use function Codefy\Framework\Helpers\view;

$settings = [];
foreach (['button-text', 'button-type', 'button-style', 'button-size', 'button-url', 'button-new-tab'] as $key) {
    $settings[$key] = $block->setting($key, true);
}
echo view('framework::frontend/website/button', [
    'button' => WebsiteButton::fromSettings($settings),
    'preview' => phpb_in_editmode(),
])->getBody();
