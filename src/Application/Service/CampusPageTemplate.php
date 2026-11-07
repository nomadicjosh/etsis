<?php

declare(strict_types=1);

namespace Application\Service;

/** Initial editor content only; an existing CMS page is never overwritten. */
final class CampusPageTemplate
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        $slugs = ['campus-hero', 'campus-pathways', 'campus-courses', 'campus-news',
            'campus-programs', 'campus-invitation'];
        $html = [];
        $blocks = [];
        foreach ($slugs as $slug) {
            $html[] = '[block slug="' . $slug . '" id="' . $slug . '"]';
            $blocks[$slug] = ['slug' => $slug, 'settings' => ['attributes' => []]];
        }
        return ['html' => [implode("\n", $html)], 'css' => '', 'blocks' => ['en' => $blocks]];
    }
}
