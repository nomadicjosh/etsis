<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\EducationBackground;
use Domain\Shared\ValueObject\EntityId;

interface EducationBackgroundRepository
{
    public function current(EntityId $person): ?EducationBackground;
    public function save(EntityId $person, EducationBackground $background, int $expectedVersion): void;
}
