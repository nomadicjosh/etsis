<?php

declare(strict_types=1);

namespace Tests\Support;

/** Isolate the native canvas fixture from application blocks that resolve live services. */
final class PageBuilderFixtureTheme extends \Vihzhuo\Theme
{
    protected function attemptExtensionBlockRegistration(string $slug, string $path): void
    {
        // Only the fictional theme's editable and dynamic blocks belong to this fixture.
    }
}
