<?php

declare(strict_types=1);

namespace Application\Service;

use Vihzhuo\Theme;
use Vihzhuo\ThemeResource;

/** Resolve physical public assets through theme inheritance while exposing Apache's clean URLs. */
final class WebsiteTheme extends Theme
{
    public function getAssetPath(string $path): string
    {
        ThemeResource::validatePath(rawurldecode(preg_split('/[?#]/', $path, 2)[0] ?? ''));
        $publicPath = 'public/' . $path;
        $resolved = parent::getAssetPath($publicPath);

        return substr($resolved, 0, -strlen($publicPath)) . $path;
    }
}
