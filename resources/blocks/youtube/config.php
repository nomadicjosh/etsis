<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'category' => trans_html('Basic'),
    'title' => trans_html('YouTube Video'),
    'icon' => 'fa fa-brands fa-square-youtube',
    'settings' => [
        'youtube_embed_url' => [
            'type' => 'text',
            'label' => trans_html('YouTube embed URL (HTTPS)'),
            'placeholder' => 'https://www.youtube.com/embed/VIDEO_ID',
            'value' => '',
        ],
        'youtube_title' => [
            'type' => 'text',
            'label' => trans_html('Video title'),
            'value' => trans_html('Video'),
        ],
        'youtube_description' => [
            'type' => 'text',
            'label' => trans_html('Video description or transcript summary'),
            'value' => '',
        ],
    ],
];
