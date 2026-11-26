<?php

declare(strict_types=1);

namespace Infrastructure\WebsiteForms;

use Domain\WebsiteForms\Service\NewsletterPolicy;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredNewsletterPolicy implements NewsletterPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->getConfigKey('sis.newsletter_enabled', false) === true;
    }

    public function permitsHost(string $host): bool
    {
        return preg_match('/^us[0-9]+\.api\.mailchimp\.com$/D', $host) === 1
        || in_array($host, $this->config->getConfigKey('sis.newsletter_hosts', []), true);
    }
}
