<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'category' => trans_html('Component'),
    'title' => trans_html('Accordion'),
    'icon' => 'fa fa-layer-group',
    'settings' => [
        'accordion_item_count' => [
            'type' => 'number',
            'label' => trans_html('Number of items (1–20)'),
            'placeholder' => '1–20',
            'value' => '2'
        ],
    ],
];
