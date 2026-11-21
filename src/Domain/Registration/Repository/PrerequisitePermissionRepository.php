<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PrerequisitePermissionRepository
{
    public function current(EntityId $student, EntityId $pursuit, EntityId $section): ?array;
    public function project(EntityId $id, array $state): void;
    public function sections(string $search, ?EntityId $after): array;
}
