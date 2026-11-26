<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Service\WebsiteTheme;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteThemeTest extends TestCase
{
    private function theme(string $slug = 'campus-quad', string $baseUrl = '/themes'): WebsiteTheme
    {
        return new WebsiteTheme([
            'folder' => dirname(__DIR__, 2) . '/public/themes',
            'folder_url' => $baseUrl,
            'parents' => [
                'campus-quad' => 'campus-harbor',
                'campus-horizon' => 'campus-harbor',
                'campus-harbor' => 'bootstrap-business',
            ],
        ], $slug);
    }

    public static function assets(): array
    {
        return [
            ['campus-harbor', 'css/theme.css', 'campus-harbor'],
            ['campus-quad', 'css/theme.css', 'campus-quad'],
            ['campus-horizon', 'css/theme.css', 'campus-horizon'],
            ['bootstrap-business', 'css/style.css', 'bootstrap-business'],
            ['campus-quad', 'css/style.css', 'bootstrap-business'],
            ['campus-horizon', 'js/script.js', 'bootstrap-business'],
            ['campus-quad', 'images/campus.svg', 'campus-harbor'],
            ['campus-horizon', 'images/favicon.ico', 'bootstrap-business'],
            ['campus-harbor', 'css/%73tyle.css?version=1#screen', 'bootstrap-business'],
        ];
    }

    #[DataProvider('assets')]
    public function testAssetsResolveTheirActualThemeWithoutExposingThePhysicalPublicDirectory(
        string $slug,
        string $path,
        string $owner
    ): void {
        self::assertSame('/themes/' . $owner . '/' . $path, $this->theme($slug)->getAssetPath($path));
        self::assertSame(
            '/campus/themes/' . $owner . '/' . $path,
            $this->theme($slug, '/campus/themes')->getAssetPath($path)
        );
    }

    public static function unsafePaths(): array
    {
        return [['/etc/passwd'], ['../view.php'], ['css/../../view.php'], ['css\\theme.css'],
            ['%2Fetc/passwd'], ['%2e%2e/view.php'], ["css/\0theme.css"]];
    }

    #[DataProvider('unsafePaths')]
    public function testPathsAreValidatedBeforeAddingThePhysicalPublicDirectory(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->theme()->getAssetPath($path);
    }
}
