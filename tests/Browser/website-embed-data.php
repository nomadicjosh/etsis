<?php

declare(strict_types=1);

$websiteEmbeds = [
    'youtube' => [
        'youtube_embed_url' => 'https://www.youtube.com/embed/abcdefghijk?start=12&autoplay=1',
        'youtube_title' => 'Fictional campus tour',
        'youtube_description' => 'Tour summary: the library and accessible entrance are beside Example Hall.',
    ],
    'google-map' => [
        'map_embed_url' => 'https://www.google.com/maps/embed?pb=!1m18!2sExample%20Hall',
        'map_title' => 'Fictional campus location',
        'map_address' => 'Example Hall, 100 Fictional Lane',
    ],
];
if (($_GET['hostile'] ?? '') === '1') {
    $websiteEmbeds['youtube']['youtube_embed_url'] = 'https://evil.invalid/embed/abcdefghijk';
    $websiteEmbeds['google-map']['map_embed_url'] = 'javascript:alert(1)';
    $websiteEmbeds['youtube']['youtube_title'] = '<img src=x onerror=alert(1)>';
    $websiteEmbeds['google-map']['map_address'] = '<script>alert(1)</script>';
}
