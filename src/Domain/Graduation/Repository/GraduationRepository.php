<?php

declare(strict_types=1);

namespace Domain\Graduation\Repository;

use Domain\Graduation\GraduationCase;
use Domain\Shared\ValueObject\EntityId;

interface GraduationRepository
{
    public function find(EntityId $pursuit): ?array;
    public function save(GraduationCase $root, int $expected): void;
    public function queue(?EntityId $after = null): array;
}
