<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\AcademicRecord\WorkPursuit;
use Domain\Shared\ValueObject\EntityId;

interface WorkPursuitRepository
{
    /** @return array<string,mixed>|null */
    public function projection(EntityId $credit): ?array;
    public function save(WorkPursuit $root, int $expectedVersion): void;
}
