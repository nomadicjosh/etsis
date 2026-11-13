<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Shared\ValueObject\EntityId;

interface MoodleSectionSyncPolicy
{
    public function enabled(): bool;
    public function actor(): EntityId;
    public function beforeDays(): int;
    public function afterDays(): int;
}
