<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Portal\ValueObject\NavigationTree;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NavigationTreeTest extends TestCase
{
    public static function unsafeUrls(): array
    {
        return array_map(static fn (string $url): array => [$url], ['javascript:alert(1)', 'data:text/html,test',
            '//outside.example.invalid', '/\\outside.example.invalid', 'https://user:pass@example.invalid/',
            "https://example.invalid/\nattack", 'mailto:test@example.invalid?body=private', 'ftp://example.invalid/']);
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeLinksAreRejected(string $url): void
    {
        $this->expectException(ValidationException::class);
        NavigationTree::requireUrl($url);
    }

    public function testSafeLocalWebEmailAndTelephoneLinksAreAllowed(): void
    {
        foreach (
            ['/', '#admissions', '/portal/courses/?term=fictional#details', 'https://example.invalid/',
            'http://example.invalid/', 'mailto:office@example.invalid', 'tel:+1(555)010-1234'] as $url
        ) {
            NavigationTree::requireUrl($url);
            self::assertNotEmpty($url);
        }
    }

    public function testDepthDuplicateIdsUnknownFieldsAndPublicationReadinessAreBounded(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFixture.php';
        $item = \Tests\Support\WebsiteFixture::item(...);
        foreach (
            [[$item(), $item()], [array_replace($item(), ['markup' => '<script>'])],
            [$item('one', 'group', children: [$item('two', 'group', children: [
                $item('three', 'group', children: [$item('four')]),
            ])])], [$item('group', 'group')], array_map(
                static fn (int $number): array => $item('link-' . $number),
                range(1, 101),
            )] as $tree
        ) {
            try {
                new NavigationTree($tree, publishing: true);
                self::fail('An invalid menu was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
