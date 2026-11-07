<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Support\CodefyServiceProvider;
use Vihzhuo\Extensions;

final class CampusBlocksServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $slugs = ['campus-hero', 'campus-pathways', 'campus-courses', 'campus-news',
            'campus-programs', 'campus-invitation'];
        foreach ($slugs as $slug) {
            Extensions::registerBlock($slug, $this->codefy->resourcePath() . '/blocks/' . $slug);
        }
    }
}
