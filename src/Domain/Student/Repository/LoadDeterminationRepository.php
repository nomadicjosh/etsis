<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface LoadDeterminationRepository
{
    public function find(EntityId $id): ?array;
    public function insert(array $row): void;
    /** @return list<array<string,mixed>> */
    public function page(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        ?EntityId $after,
    ): array;
}
