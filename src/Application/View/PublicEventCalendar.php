<?php

declare(strict_types=1);

namespace Application\View;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LengthException;

use function Codefy\Framework\Helpers\trans;

/** A bounded RFC5545 presentation of already public meetings, with no invitations or alarms. */
final class PublicEventCalendar
{
    public const int MAX_BYTES = 2097152;

    public function render(array $events, string $feedUrl): string
    {
        if (count($events) > 500 || preg_match('/[\x00-\x20\x7f]/', $feedUrl)) {
            throw new InvalidArgumentException('Use a bounded public calendar and canonical URL.');
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
                throw new LengthException('The public calendar feed exceeds its byte limit.');
            }
        };
        $properties = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//etSIS//Public campus events//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . $this->text(trans('Public campus events')),
        ];
        foreach ($properties as $line) {
            $append($line);
        }
        $seen = [];
        foreach ($events as $event) {
            if (
                !preg_match('/\A[a-f0-9]{64}\z/', $event['uid']) || isset($seen[$event['uid']])
                || !is_int($event['version']) || $event['version'] < 1 || $event['version'] > 2147483647
                || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $event['calendar_day'])
            ) {
                throw new InvalidArgumentException('Use distinct public meeting identities and revisions.');
            }
            $seen[$event['uid']] = true;
            $starts = $this->instant($event['starts_at']);
            $ends = $this->instant($event['ends_at']);
            if ($ends <= $starts) {
                throw new InvalidArgumentException('Use ordered public meeting times.');
            }
            $properties = [
                'BEGIN:VEVENT',
                'UID:' . $event['uid'] . '@etsis',
                'DTSTAMP:' . $this->instant($event['published_at']),
                'SEQUENCE:' . $event['version'],
                'DTSTART:' . $starts,
                'DTEND:' . $ends,
                'SUMMARY:' . $this->text($event['title']),
                'DESCRIPTION:' . $this->text($event['description']),
                'LOCATION:' . $this->text($event['room_label'] . ' · ' . $event['building_label']),
                'URL:' . substr($feedUrl, 0, -strlen('calendar.ics')) . '?date='
                    . $event['calendar_day'] . '&day=' . $event['calendar_day'],
                'CLASS:PUBLIC', 'STATUS:CONFIRMED', 'TRANSP:TRANSPARENT', 'END:VEVENT',
            ];
            foreach ($properties as $line) {
                $append($line);
            }
        }
        $append('END:VCALENDAR');
        return $body;
    }

    private function text(string $text): string
    {
        if (
            strlen($text) > 65536 || !mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)
        ) {
            throw new InvalidArgumentException('Use valid public calendar text.');
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
            throw new InvalidArgumentException('Use an explicit public calendar instant.');
        }
        $date = new DateTimeImmutable($value);
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidArgumentException('Use a valid public calendar instant.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
