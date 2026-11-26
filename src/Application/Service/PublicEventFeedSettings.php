<?php

declare(strict_types=1);

namespace Application\Service;

use InvalidArgumentException;
use Qubus\Config\ConfigContainer;

/** Deployment-owned public sharing; never derives a feed origin from request headers. */
final readonly class PublicEventFeedSettings
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function url(): ?string
    {
        $settings = $this->config->getConfigKey('portal.public_event_feed', []);
        if (!is_array($settings) || array_diff(array_keys($settings), ['enabled']) !== []) {
            throw new InvalidArgumentException('Use valid public calendar feed settings.');
        }
        $enabled = match (array_key_exists('enabled', $settings) ? $settings['enabled'] : false) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false', '' => false,
            default => throw new InvalidArgumentException('Use a valid public calendar feed flag.'),
        };
        if (!$enabled) {
            return null;
        }
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
        return rtrim($base, '/') . '/portal/events/calendar.ics';
    }

    public function visibleUrl(): ?string
    {
        try {
            return $this->url();
        } catch (\Throwable) {
            return null;
        }
    }
}
