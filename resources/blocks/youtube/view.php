<?php

declare(strict_types=1);

use Domain\Portal\ValueObject\ExternalEmbed;

use function Codefy\Framework\Helpers\view;

echo view('framework::frontend/website/embed', [
    'embed' => ExternalEmbed::youtube($block->setting('youtube_embed_url', true)),
    'provider' => 'youtube',
    'title' => $block->setting('youtube_title', true),
    'description' => $block->setting('youtube_description', true),
    'preview' => phpb_in_editmode(),
])->getBody();
