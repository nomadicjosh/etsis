<?php

declare(strict_types=1);

namespace Tests\Support;

/** Isolate the native canvas fixture from application blocks that resolve live services. */
final class PageBuilderFixtureTheme extends \Vihzhuo\Theme
{
    protected function attemptExtensionBlockRegistration(string $slug, string $path): void
    {
        // Allow only these native blocks alongside the fictional theme blocks.
        if (in_array($slug, ['regular-form', 'youtube', 'google-map', 'button', 'alert', 'alert-text'], true)) {
            parent::attemptExtensionBlockRegistration($slug, $path);
        }
    }
}
