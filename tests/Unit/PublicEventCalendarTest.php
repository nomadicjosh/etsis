<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\View\PublicEventCalendar;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicEventCalendarTest extends TestCase
{
    private const string URL = 'https://campus.example.invalid/portal/events/calendar.ics';

    private function event(): array
    {
        return ['uid' => hash('sha256', 'fictional meeting'), 'version' => 2,
            'published_at' => '2026-10-30T12:00:00-07:00',
            'starts_at' => '2026-11-01T01:30:00-07:00', 'ends_at' => '2026-11-01T01:30:00-08:00',
            'calendar_day' => '2026-11-01', 'title' => 'Public open house',
            'description' => "A public invitation; explore, learn\\meet.\nAll welcome.",
            'room_label' => 'LIB.101', 'building_label' => 'Fictional library'];
    }

    private function unfold(string $body): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $body);
    }

    public function testCompleteCalendarHasUtcInstantsStableIdentityRevisionAndNoInvitationOrAlarm(): void
    {
        $renderer = new PublicEventCalendar();
        $body = $renderer->render([$this->event()], self::URL);
        $plain = $this->unfold($body);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $body);
        self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $body);
        self::assertStringContainsString("DTSTART:20261101T083000Z\r\nDTEND:20261101T093000Z", $plain);
        self::assertStringContainsString('DTSTAMP:20261030T190000Z', $plain);
        self::assertStringContainsString('SEQUENCE:2', $plain);
        self::assertStringContainsString(
            'DESCRIPTION:A public invitation\; explore\, learn\\\\meet.\\nAll welcome.',
            $plain
        );
        self::assertStringContainsString(
            'URL:https://campus.example.invalid/portal/events/?date=2026-11-01&day=2026-11-01',
            $plain
        );
        foreach (['METHOD:', 'VALARM', 'ATTENDEE:', 'ORGANIZER:', 'X-ALT-DESC', 'TZID'] as $absent) {
            self::assertStringNotContainsString($absent, $plain);
        }
        preg_match('/^UID:(.+)$/m', $plain, $uid);
        self::assertSame($body, $renderer->render([$this->event()], self::URL));
        $moved = $this->unfold($renderer->render(
            [$this->event()],
            'https://other.example.invalid/portal/events/calendar.ics'
        ));
        preg_match('/^UID:(.+)$/m', $moved, $movedUid);
        self::assertSame(trim($uid[1]), trim($movedUid[1]));
        self::assertMatchesRegularExpression('/^UID:[a-f0-9]{64}@etsis\r$/m', $plain);
    }

    public function testTextCannotInjectCalendarComponentsAndUtf8LinesStayWithin75Octets(): void
    {
        $event = $this->event();
        $event['title'] = str_repeat('日本語 🎓 ', 25);
        $event['description'] = "END:VEVENT\r\nBEGIN:VALARM\r\nACTION:EMAIL\rBEGIN:VEVENT";
        $body = new PublicEventCalendar()->render([$event], self::URL);
        self::assertSame(1, preg_match_all('/^BEGIN:VEVENT\r$/m', $body));
        self::assertSame(1, preg_match_all('/^END:VEVENT\r$/m', $body));
        self::assertStringNotContainsString("\r\nBEGIN:VALARM", $body);
        self::assertStringContainsString('SUMMARY:' . $event['title'], $this->unfold($body));
        self::assertStringContainsString(
            'DESCRIPTION:END:VEVENT\\nBEGIN:VALARM\\nACTION:EMAIL\\nBEGIN:VEVENT',
            $this->unfold($body)
        );
        foreach (explode("\r\n", $body) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $body);
    }

    public function testAnEmptyCalendarIsCompleteAndContainsNoStaleMeeting(): void
    {
        $body = new PublicEventCalendar()->render([], self::URL);
        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringNotContainsString('BEGIN:VEVENT', $body);
    }

    public static function invalid(): array
    {
        return [['title', "bad\x00text"], ['description', "\xff"], ['version', 0], ['version', '1'],
            ['uid', "bad\r\nUID:injected"], ['starts_at', 'tomorrow'],
            ['starts_at', '2026-02-30T10:00:00Z'], ['ends_at', '2026-11-01T08:00:00Z'],
            ['calendar_day', '2026-11-01&PRIVATE=1']];
    }

    #[DataProvider('invalid')]
    public function testMalformedSourceValuesCannotBecomeAFeed(string $field, mixed $value): void
    {
        $event = $this->event();
        $event[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        new PublicEventCalendar()->render([$event], self::URL);
    }

    public function testDuplicateMeetingIdentitiesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicEventCalendar()->render([$this->event(), $this->event()], self::URL);
    }

    public function testAnOversizedFeedIsRefusedInsteadOfTruncated(): void
    {
        $events = [];
        for ($i = 0; $i < 500; $i++) {
            $events[] = array_replace($this->event(), [
                'uid' => hash('sha256', (string) $i), 'description' => str_repeat('text', 1400),
            ]);
        }
        $this->expectException(LengthException::class);
        new PublicEventCalendar()->render($events, self::URL);
    }
}
