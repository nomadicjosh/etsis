<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Service\MoodleSectionSyncPolicy;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredMoodleSectionSyncPolicy implements MoodleSectionSyncPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }
    public function enabled(): bool
    {
        return $this->config->getConfigKey("moodle.enabled", false) === true &&
        $this->config->getConfigKey("moodle.section_sync_enabled", false) === true;
    }
    public function actor(): EntityId
    {
        return new EntityId((string) $this->config->getConfigKey("moodle.sync_actor", ""));
    }
    public function beforeDays(): int
    {
        return $this->days("access_before_days", 365);
    }
    public function afterDays(): int
    {
        return $this->days("access_after_days", 3650);
    }
    private function days(string $key, int $max): int
    {
        $value = $this->config->getConfigKey("moodle." . $key, 0);
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new \InvalidArgumentException("Use a bounded institutional Moodle access window.");
        }
        return $value;
    }
}
