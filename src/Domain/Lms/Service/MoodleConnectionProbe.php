<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleCapabilities;

/** Operator-only read-only diagnostic. No arbitrary function execution or synchronization contract. */
interface MoodleConnectionProbe
{
    public function enabled(): bool;
    public function check(): MoodleCapabilities;
}
