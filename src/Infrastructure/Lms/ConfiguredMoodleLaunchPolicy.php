<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Service\MoodleLaunchPolicy;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredMoodleLaunchPolicy implements MoodleLaunchPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }
    public function enabled(): bool
    {
        return $this->config->boolean("moodle.enabled", false) &&
        $this->config->boolean("moodle.launch_enabled", false) &&
        preg_match('/^[a-f0-9]{64}$/D', $this->config->string("moodle.launch_secret", "")) === 1;
    }
    public function site(): MoodleSiteUrl
    {
        return new MoodleSiteUrl($this->config->string("moodle.url", ""));
    }
}
