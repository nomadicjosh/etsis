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
        return new CampusCalendarLinks($this->config)->publicEvents();
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
