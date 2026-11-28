<?php

declare(strict_types=1);

namespace Infrastructure\Payments;

use Domain\Payments\Service\GatewayPolicy;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredGatewayPolicy implements GatewayPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->boolean('portal.payments.enabled', false) && $this->baseUrl() !== null;
    }

    public function allowTest(): bool
    {
        return in_array($this->config->string('app.env', 'production'), ['local', 'development', 'testing'], true);
    }

    public function baseUrl(): ?string
    {
        $url = rtrim($this->config->string('app.url', ''), '/');
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && isset($p['host'])
        && !isset($p['query']) && !isset($p['fragment'])
        && !isset($p['user']) && !isset($p['pass']) ? $url : null;
    }
}
