<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\View\WebsiteButton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteButtonTest extends TestCase
{
    public function testEarlierUnspecifiedButtonsCannotImplicitlySubmitAndExplicitSubmitsKeepTheirMeaning(): void
    {
        foreach ([[], ['button-type' => ''], ['button-type' => 'reset'], ['button-type' => ['submit']]] as $values) {
            $button = WebsiteButton::fromSettings($values + ['button-url' => '/apply/', 'button-new-tab' => '1']);
            self::assertSame('button', $button->type);
            self::assertNull($button->destination);
            self::assertFalse($button->newTab);
        }
        self::assertSame('submit', WebsiteButton::fromSettings(['button-type' => 'submit'])->type);
    }

    public static function destinations(): array
    {
        return [['/'], ['/portal/programs/?name=Arts&level=ug'], ['#page-title'], ['https://example.invalid/apply'],
            ['http://example.invalid/information'], ['mailto:admissions@example.invalid'], ['tel:+12025550100']];
    }

    #[DataProvider('destinations')]
    public function testLinksShareTheMenuDestinationPolicyWithoutGuessingOrDecodingTheTarget(string $url): void
    {
        $button = WebsiteButton::fromSettings(['button-type' => 'link', 'button-url' => $url,
            'button-text' => 'Fictional information', 'button-new-tab' => '1']);
        self::assertSame($url, $button->destination);
        self::assertSame('link', $button->type);
        self::assertSame(!str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:'), $button->newTab);
    }

    public static function unsafeDestinations(): array
    {
        return [[''], ['//example.invalid'], ['javascript:alert(1)'], ['data:text/html,example'],
            ['vbscript:example'], ['https://person:password@example.invalid'], ["/\n/other.invalid"],
            ['/\\other.invalid'], ['https://example.invalid/ with-space'], ['relative/path'],
            ['mailto:admissions@example.invalid?body=private'], ['tel:javascript:alert(1)'],
            [str_repeat('/x', 1025)], [['/apply/']], [false]];
    }

    #[DataProvider('unsafeDestinations')]
    public function testInvalidTargetsStayUnavailableAndCannotOpenAnotherContext(mixed $url): void
    {
        $button = WebsiteButton::fromSettings(['button-type' => 'link', 'button-url' => $url, 'button-new-tab' => '1']);
        self::assertSame('link', $button->type);
        self::assertNull($button->destination);
        self::assertFalse($button->newTab);
    }

    public function testUntrustedPresentationSettingsCannotBecomeMarkupAttributesOrImplicitBehavior(): void
    {
        $label = 'Fictional <img src=x onerror=alert(1)> & information';
        $button = WebsiteButton::fromSettings(['button-type' => 'link', 'button-url' => '/apply/',
            'button-text' => $label, 'button-style' => 'btn-primary" onclick="alert(1)',
            'button-size' => 'btn-lg position-fixed', 'button-new-tab' => true]);
        self::assertSame($label, $button->label);
        self::assertSame('btn website-button btn-primary', $button->classes);
        self::assertFalse($button->newTab);
        foreach (['', str_repeat('x', 201), "Fictional\nlabel", "\xFF", ['label']] as $invalid) {
            self::assertSame('Continue', WebsiteButton::fromSettings(['button-text' => $invalid])->label);
        }
    }

    public function testEarlierExplicitAppearanceAndTextRemainAvailable(): void
    {
        $button = WebsiteButton::fromSettings(['button-type' => 'submit', 'button-text' => 'Send inquiry',
            'button-style' => 'btn-success', 'button-size' => 'btn-sm']);
        self::assertSame('submit', $button->type);
        self::assertSame('Send inquiry', $button->label);
        self::assertSame('btn website-button btn-success btn-sm', $button->classes);
    }
}
