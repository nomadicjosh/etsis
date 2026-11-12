<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

/** Request intake is separate from permission to execute remote provisioning. */
interface MoodleAccountRequestPolicy
{
    public function enabled(): bool;
}
