<?php

declare(strict_types=1);

namespace Application\View;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LengthException;

/** Bounded RFC5545 encoding. Classification is metadata; callers must authorize their own source. */
final class CalendarDocument
{
    public const int MAX_BYTES = 2097152;

    public function render(#[\SensitiveParameter] array $events, string $name, bool $private): string
    {
        if (count($events) > 500) {
            throw new InvalidArgumentException('Use a bounded calendar and canonical URL.');
        }
        $body = '';
        $append = static function (string $line) use (&$body): void {
            while (strlen($line) > 75) {
                $part = mb_strcut($line, 0, 75, 'UTF-8');
                $body .= $part . "\r\n";
                $line = ' ' . substr($line, strlen($part));
            }
            $body .= $line . "\r\n";
            if (strlen($body) > self::MAX_BYTES) {
                throw new LengthException('The calendar feed exceeds its byte limit.');
            }
        };
        $properties = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//etSIS//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . $this->text($name),
        ];
        foreach ($properties as $line) {
            $append($line);
        }
        $seen = [];
        foreach ($events as $event) {
            if (
                !preg_match('/\A[a-f0-9]{64}\z/', $event['uid']) || isset($seen[$event['uid']])
                || !is_int($event['version']) || $event['version'] < 1 || $event['version'] > 2147483647
            ) {
                throw new InvalidArgumentException('Use distinct meeting identities and revisions.');
            }
            $seen[$event['uid']] = true;
            $this->url($event['url']);
            $starts = $this->instant($event['starts_at']);
            $ends = $this->instant($event['ends_at']);
            $status = $event['status'] ?? 'CONFIRMED';
            if ($ends <= $starts || !in_array($status, ['CONFIRMED', 'CANCELLED'], true)) {
                throw new InvalidArgumentException('Use ordered meeting times.');
            }
            $properties = [
                'BEGIN:VEVENT',
                'UID:' . $event['uid'] . '@etsis',
                'DTSTAMP:' . $this->instant($event['updated_at']),
                'SEQUENCE:' . $event['version'],
                'DTSTART:' . $starts,
                'DTEND:' . $ends,
                'SUMMARY:' . $this->text($event['title']),
                'DESCRIPTION:' . $this->text($event['description']),
                'LOCATION:' . $this->text($event['location']),
                'URL:' . $event['url'],
                $private ? 'CLASS:PRIVATE' : 'CLASS:PUBLIC',
                'STATUS:' . $status,
                $private && $status !== 'CANCELLED' ? 'TRANSP:OPAQUE' : 'TRANSP:TRANSPARENT',
                'END:VEVENT',
            ];
            foreach ($properties as $line) {
                $append($line);
            }
        }
        $append('END:VCALENDAR');
        return $body;
    }

    private function url(string $url): void
    {
        $parts = parse_url($url);
        if (
            strlen($url) > 4096 || !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('Use a safe canonical calendar link.');
        }
    }

    private function text(string $text): string
    {
        if (
            strlen($text) > 65536 || !mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)
        ) {
            throw new InvalidArgumentException('Use valid calendar text.');
        }
        return strtr(str_replace(["\r\n", "\r"], "\n", $text), [
            '\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\n" => '\\n',
        ]);
    }

    private function instant(string $value): string
    {
        $pattern = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
        . '(?:Z|[+-][0-9]{2}:[0-9]{2})\z/';
        if (!preg_match($pattern, $value)) {
            throw new InvalidArgumentException('Use an explicit calendar instant.');
        }
        $date = new DateTimeImmutable($value);
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidArgumentException('Use a valid calendar instant.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
