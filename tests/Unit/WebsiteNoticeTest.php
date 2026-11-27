<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\View\WebsiteNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteNoticeTest extends TestCase
{
    public static function contexts(): array
    {
        return array_map(
            static fn(string $tone): array => ['alert-' . $tone],
            ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark']
        );
    }

    #[DataProvider('contexts')]
    public function testEarlierContextChoicesRemainValidWithoutAddingDismissal(string $context): void
    {
        $notice = WebsiteNotice::fromSettings(['alert-context' => $context]);
        self::assertSame($context, $notice->context);
        self::assertSame($notice->tone, $notice->label);
        self::assertNotSame('', $notice->tone);
        self::assertMatchesRegularExpression('/^[a-z-]+$/D', $notice->icon);
        self::assertFalse($notice->dismissible);
    }

    public static function unsafeSettings(): array
    {
        return [['alert-primary" onclick="alert(1)', ''], [['alert-danger'], "Fictional\nlabel"],
            [false, str_repeat('x', 161)], ['other', ['label']], [null, "\xFF"]];
    }

    #[DataProvider('unsafeSettings')]
    public function testUnsupportedPresentationCannotBecomeAttributesAndInvalidLabelsStayReadable(
        mixed $context,
        mixed $label,
    ): void {
        $notice = WebsiteNotice::fromSettings(['alert-context' => $context, 'alert-label' => $label,
            'alert-dismissible' => 'true']);
        self::assertSame('alert-primary', $notice->context);
        self::assertSame('Information', $notice->label);
        self::assertFalse($notice->dismissible);
    }

    public function testDismissalIsExplicitAndCustomWordingStaysRawUntilTheViewEscapesIt(): void
    {
        $label = 'Fictional <img src=x onerror=alert(1)> & notice';
        $notice = WebsiteNotice::fromSettings(['alert-context' => 'alert-warning', 'alert-label' => $label,
            'alert-dismissible' => '1']);
        self::assertSame($label, $notice->label);
        self::assertSame('Warning', $notice->tone);
        self::assertTrue($notice->dismissible);
        foreach ([true, 1, '0', '', null] as $value) {
            self::assertFalse(WebsiteNotice::fromSettings(['alert-dismissible' => $value])->dismissible);
        }
    }
}
