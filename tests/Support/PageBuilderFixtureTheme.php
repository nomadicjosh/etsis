<?php

declare(strict_types=1);

namespace Tests\Support;

/** Isolate the native canvas fixture from application blocks that resolve live services. */
final class PageBuilderFixtureTheme extends \Vihzhuo\Theme
{
    protected function attemptExtensionBlockRegistration(string $slug, string $path): void
    {
        // Allow only the native managed form alongside the fictional theme blocks.
        if ($slug === 'regular-form') {
            parent::attemptExtensionBlockRegistration($slug, $path);
        }
    }
}
