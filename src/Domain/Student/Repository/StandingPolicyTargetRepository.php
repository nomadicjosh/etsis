<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Read-only reference choices and explicit locks for institutional policy publication. */
interface StandingPolicyTargetRepository
{
    public function page(string $kind, string $search, ?EntityId $after): array;
    public function get(string $kind, EntityId $id): array;
    public function lock(string $kind, EntityId $id): void;
}
