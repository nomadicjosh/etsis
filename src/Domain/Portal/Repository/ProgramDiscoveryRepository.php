<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface ProgramDiscoveryRepository
{
    public function state(EntityId $program): ?array;
    public function save(EntityId $program, array $values): void;
    /** Indexed public summaries only, thirteen rows at most. */
    public function page(string $search, ?EntityId $after): array;
    public function choices(EntityId $program, ?EntityId $after): array;
}
