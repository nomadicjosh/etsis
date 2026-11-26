<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'category' => trans_html('Basic'),
    'title' => trans_html('Google Maps'),
    'icon' => 'fa fa-map-location',
    'settings' => [
        'map_embed_url' => [
            'type' => 'text',
            'label' => trans_html('Google Maps embed URL (HTTPS)'),
            'placeholder' => 'https://www.google.com/maps/embed?pb=...',
            'value' => '',
        ],
        'map_title' => [
            'type' => 'text',
            'label' => trans_html('Map title'),
            'value' => trans_html('Location map'),
        ],
        'map_address' => [
            'type' => 'text',
            'label' => trans_html('Street address or location description'),
            'value' => '',
        ],
    ],
];
