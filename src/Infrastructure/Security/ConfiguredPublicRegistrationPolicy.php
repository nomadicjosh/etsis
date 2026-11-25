<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Domain\User\Service\PublicRegistrationPolicyProvider;
use Domain\User\ValueObject\PublicRegistrationPolicy;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredPublicRegistrationPolicy implements PublicRegistrationPolicyProvider
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function current(): PublicRegistrationPolicy
    {
        return PublicRegistrationPolicy::from($this->config->getConfigKey('registration', []));
    }
}
