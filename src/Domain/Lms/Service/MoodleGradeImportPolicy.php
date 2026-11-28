<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Shared\ValueObject\EntityId;

interface MoodleGradeImportPolicy
{
    public function enabled(): bool;
    public function actor(): EntityId;
}
