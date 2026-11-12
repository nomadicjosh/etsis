<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleInstallation;

interface MoodleInstanceReader
{
    public function enabled(): bool;
    public function identify(): MoodleInstallation;
}
