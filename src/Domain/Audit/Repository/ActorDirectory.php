<?php

declare(strict_types=1);

namespace Domain\Audit\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Current display names only; retained attribution is the original account identity. */
interface ActorDirectory
{
    /** @param list<EntityId> $actors
     * @return array<string,string>
     */
    public function names(array $actors): array;
}
