<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'category' => trans_html('Component'),
    'title' => trans_html('Carousel'),
    'icon' => 'fa fa-panorama',
    'settings' => [
        'carousel_label' => [
            'type' => 'text',
            'label' => trans_html('Accessible carousel name'),
            'value' => trans_html('Featured information'),
        ],
        'carousel_item_count' => [
            'type' => 'number',
            'label' => trans_html('Number of items (1–20)'),
            'placeholder' => '1–20',
            'value' => '2'
        ],
    ],
];
