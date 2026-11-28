<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Service\MoodleGradeImportPolicy;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredMoodleGradeImportPolicy implements MoodleGradeImportPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->getConfigKey("moodle.enabled", false) === true &&
        $this->config->getConfigKey(
            "moodle.grade_import_mode",
            "disabled",
        ) === "reviewed_final";
    }

    public function actor(): EntityId
    {
        return new EntityId(
            (string) $this->config->getConfigKey("moodle.sync_actor", ""),
        );
    }
}
