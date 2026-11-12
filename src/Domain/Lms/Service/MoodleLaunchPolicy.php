<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleSiteUrl;

interface MoodleLaunchPolicy
{
    public function enabled(): bool;
    public function site(): MoodleSiteUrl;
}
