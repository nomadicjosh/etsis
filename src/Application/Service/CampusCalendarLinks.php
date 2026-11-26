<?php

declare(strict_types=1);

namespace Application\Service;

use InvalidArgumentException;
use Qubus\Config\ConfigContainer;

final readonly class CampusCalendarLinks
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function publicEvents(): string
    {
        return $this->absolute('/portal/events/calendar.ics');
    }

    public function timetable(): string
    {
        return $this->absolute('/portal/timetable/');
    }

    private function absolute(string $path): string
    {
        $base = $this->config->string('app.url', '');
        $parts = parse_url($base);
        if (
            strlen($base) > 2048 || !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $base)
            || filter_var($base, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('Configure a valid canonical calendar origin.');
        }
        return rtrim($base, '/') . $path;
    }
}
