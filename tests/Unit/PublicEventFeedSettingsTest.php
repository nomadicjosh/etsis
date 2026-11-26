<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Service\PublicEventFeedSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class PublicEventFeedSettingsTest extends TestCase
{
    public function testDefaultIsDisabledAndEnablingUsesOnlyTheCanonicalConfiguredUrl(): void
    {
        $config = Collection::factory([]);
        $settings = new PublicEventFeedSettings($config);
        self::assertNull($settings->url());
        $config->setConfigKey('portal', ['public_event_feed' => ['enabled' => true]]);
        $config->setConfigKey('app', ['url' => 'https://campus.example.invalid/base/']);
        self::assertSame('https://campus.example.invalid/base/portal/events/calendar.ics', $settings->url());
        $config->setConfigKey('portal', ['public_event_feed' => ['enabled' => false]]);
        self::assertNull($settings->visibleUrl());
    }

    public static function invalid(): array
    {
        return [['yes', 'https://campus.example.invalid'], [[], 'https://campus.example.invalid'],
            [null, 'https://campus.example.invalid'], [true, ''], [true, '/relative'],
            [true, 'https://user:PRIVATE@campus.example.invalid'],
            [true, 'https://campus.example.invalid/?token=PRIVATE'],
            [true, 'https://campus.example.invalid/#PRIVATE'], [true, "https://campus.example.invalid/\r\nPRIVATE"],
            [true, 'javascript:PRIVATE'], [true, 'https://campus.example.invalid/\\PRIVATE']];
    }

    #[DataProvider('invalid')]
    public function testMalformedSettingsHideTheLinkAndRefuseTheEndpoint(mixed $enabled, string $url): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('portal', ['public_event_feed' => ['enabled' => $enabled]]);
        $config->setConfigKey('app', ['url' => $url]);
        $settings = new PublicEventFeedSettings($config);
        self::assertNull($settings->visibleUrl());
        $this->expectException(InvalidArgumentException::class);
        $settings->url();
    }
}
