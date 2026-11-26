<?php

declare(strict_types=1);

use Domain\Portal\ValueObject\ExternalEmbed;

use function Codefy\Framework\Helpers\view;

$address = $block->setting('map_address', true);
echo view('framework::frontend/website/embed', [
    'embed' => ExternalEmbed::map($block->setting('map_embed_url', true), $address),
    'provider' => 'map',
    'title' => $block->setting('map_title', true),
    'description' => $address,
    'preview' => phpb_in_editmode(),
])->getBody();
