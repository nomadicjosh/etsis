<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface StudentRequirementsRepository
{
    /** @return array<string, mixed>|null */
    public function get(EntityId $assignment): ?array;
    /** @param array<string, mixed> $selection */
    public function project(EntityId $assignment, array $selection, int $position): void;
}
