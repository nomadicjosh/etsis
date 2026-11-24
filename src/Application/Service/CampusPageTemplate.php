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
            'campus-programs', 'campus-events', 'campus-invitation'];
        $html = [];
        $blocks = [];
        $components = [];
        foreach ($slugs as $slug) {
            // Native Vihzhuo reserves ID-prefixed instances for editable body content.
            $instance = 'ID' . $slug;
            $html[] = '[block slug="' . $slug . '" id="' . $instance . '"]';
            $blocks[$instance] = ['slug' => $slug, 'settings' => ['attributes' => []]];
            $components[] = ['tagName' => 'phpb-block', 'attributes' => ['slug' => $slug, 'id' => $instance]];
        }
        return ['html' => [implode("\n", $html)], 'css' => '',
            'components' => [$components], 'blocks' => ['en' => $blocks]];
    }
}
