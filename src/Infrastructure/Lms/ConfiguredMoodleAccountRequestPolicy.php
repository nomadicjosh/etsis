<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Service\MoodleAccountRequestPolicy;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredMoodleAccountRequestPolicy implements MoodleAccountRequestPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->getConfigKey("moodle.enabled", false) === true &&
        $this->config->getConfigKey("moodle.account_requests_enabled", false) === true;
    }
}
