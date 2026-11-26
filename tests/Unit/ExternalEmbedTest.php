<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Portal\ValueObject\ExternalEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalEmbedTest extends TestCase
{
    public function testYouTubeKeepsTheVideoAndTimingWhileForcingDeliberateAccessiblePlayback(): void
    {
        $embed = ExternalEmbed::youtube('https://www.youtube.com/embed/abcdefghijk?si=old&start=12&end=90'
            . '&autoplay=1&controls=0&disablekb=1&enablejsapi=1&origin=https://other.invalid');
        self::assertNotNull($embed);
        self::assertSame('www.youtube-nocookie.com', parse_url($embed->source, PHP_URL_HOST));
        parse_str(parse_url($embed->source, PHP_URL_QUERY), $query);
        self::assertSame(['autoplay' => '0', 'controls' => '1', 'disablekb' => '0', 'playsinline' => '1',
            'cc_load_policy' => '1', 'rel' => '0', 'start' => '12', 'end' => '90'], $query);
        self::assertSame('https://www.youtube.com/watch?v=abcdefghijk&t=12', $embed->link);
    }

    public function testPrivacyEnhancedAndBareYouTubeHostsUseTheSameCanonicalDestination(): void
    {
        self::assertEquals(
            ExternalEmbed::youtube('https://www.youtube-nocookie.com/embed/abcdefghijk'),
            ExternalEmbed::youtube('https://youtube.com/embed/abcdefghijk')
        );
    }

    public function testSharedMapRetainsItsLocationAndProvidesAnAddressSearchAlternative(): void
    {
        $embed = ExternalEmbed::map(
            'https://www.google.com/maps/embed?pb=!1m18!2sExample%20Hall&unknown=value',
            'Example Hall, 100 Fictional Lane'
        );
        self::assertNotNull($embed);
        parse_str(parse_url($embed->source, PHP_URL_QUERY), $query);
        self::assertSame(['pb' => '!1m18!2sExample Hall'], $query);
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Example%20Hall%2C%20100%20Fictional%20Lane',
            $embed->link
        );
        $withoutAddress = ExternalEmbed::map('https://www.google.com/maps/embed?pb=example');
        self::assertSame($withoutAddress->source, $withoutAddress->link);
    }

    public static function modes(): array
    {
        return [['place'], ['view'], ['directions'], ['streetview'], ['search']];
    }

    #[DataProvider('modes')]
    public function testOfficialMapsApiModesRetainBrowserRestrictedKeysAndEncodedParameters(string $mode): void
    {
        $url = 'https://www.google.com/maps/embed/v1/' . $mode . '?key=fictional-restricted-key&q=Example%20Hall';
        self::assertSame($url, ExternalEmbed::map($url)->source);
    }

    public static function unsafe(): array
    {
        return [
            ['javascript:alert(1)'], ['//www.youtube.com/embed/abcdefghijk'],
            ['http://www.youtube.com/embed/abcdefghijk'], ['https://www.youtube.com.evil.invalid/embed/abcdefghijk'],
            ['https://www.youtube.com@evil.invalid/embed/abcdefghijk'],
            ['https://user:password@www.youtube.com/embed/abcdefghijk'],
            ['https://www.youtube.com:443/embed/abcdefghijk'], ['https://www.youtube.com/embed/abcdefghijk#fragment'],
            ["https://www.youtube.com\\@evil.invalid/embed/abcdefghijk"],
            ["https://www.youtube.com/embed/abcdefghijk\n"], ['https://www.youtube.com/embed/abcdefghijk?x=%0a'],
            ['https://www.youtube.com/embed/abcdefghijk?start[]=1'], ['https://www.youtube.com/watch?v=abcdefghijk'],
            ['https://www.youtube.com/embed/abcdefghij'], ['https://www.youtube.com/embed/abcdefghijk/extra'],
            ['https://www.youtube.com/embed/abcdefghijk?start=-1'],
            ['https://www.youtube.com/embed/abcdefghijk?start=86401'],
            ['https://www.youtube.com/embed/abcdefghijk?start=10&end=9'],
            ['https://www.youtube.com/embed/abcdefghijk?start=1.5'],
            ['https://www.google.com.evil.invalid/maps/embed?pb=example'],
            ['https://www.google.com/url?url=https://evil.invalid'],
            ['https://www.google.com/maps/embed/../url?pb=example'],
            ['https://www.google.com/maps/embed?pb[]=example'], ['https://www.google.com/maps/embed?pb='],
            ['https://www.google.com/maps/embed/v1/place?q=example'],
            ['https://www.google.com/maps/embed?pb=' . str_repeat('x', 8192)],
            ['https://www.google.com/maps/embed?pb=example&' . http_build_query(array_fill(0, 31, 'value'))],
        ];
    }

    #[DataProvider('unsafe')]
    public function testMalformedOrUnapprovedDestinationsNeverBecomeExternalFrames(string $url): void
    {
        self::assertNull(ExternalEmbed::youtube($url));
        self::assertNull(ExternalEmbed::map($url));
    }
}
