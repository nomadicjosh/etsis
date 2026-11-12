<?php

declare(strict_types=1);

use Domain\Lms\ValueObject\MoodleSiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoodleSiteUrlTest extends TestCase
{
    public function testOnlyAuthorityCaseDefaultHttpsPortAndTrailingSlashAreNormalized(): void
    {
        $site = new MoodleSiteUrl("HTTPS://MOODLE.EXAMPLE.INVALID:443/campus/");
        self::assertSame("https://moodle.example.invalid/campus", $site->value);
        self::assertTrue($site->matches(new MoodleSiteUrl("https://moodle.example.invalid/campus")));
        foreach (
            [
                "https://moodle.example.invalid/Campus",
                "https://moodle.example.invalid:8443/campus",
                "https://moodle.example.invalid",
                "https://another.example.invalid/campus",
            ]
            as $other
        ) {
            self::assertFalse($site->matches(new MoodleSiteUrl($other)));
        }
        self::assertSame("https://[::1]/campus", new MoodleSiteUrl("https://[::1]:443/campus/")->value);
    }
    public static function invalidUrls(): iterable
    {
        foreach (
            [
                "http://moodle.example.invalid",
                "",
                "/campus",
                "//moodle.example.invalid",
                "https://user:secret@moodle.example.invalid",
                "https://moodle.example.invalid?token=secret",
                "https://moodle.example.invalid#secret",
                "https://moodle.example.invalid/\r\nsecret",
                "https://moodle.example.invalid/a/../campus",
                "https://moodle.example.invalid/%2e/campus",
                "https://moodle.example.invalid/a/%2e%2E/campus",
                "https://moodle.example.invalid/\\campus",
                "https://moodle.example.invalid:0",
                "https://moodle.example.invalid/" . str_repeat("a", 2048),
            ]
            as $url
        ) {
            yield [$url];
        }
    }
    #[DataProvider("invalidUrls")]
    public function testInvalidSiteAddressesAreRejectedWithoutEchoingInput(string $url): void
    {
        try {
            new MoodleSiteUrl($url);
            self::fail("Invalid URL accepted.");
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString("secret", $error->getMessage());
        }
    }
}
