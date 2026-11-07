<?php

declare(strict_types=1);

namespace Domain\Workspace\Repository;

use Domain\Shared\ValueObject\EntityId;

interface OperationsRepository
{
    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function page(string $context, EntityId $id, ?EntityId $after): array;
}
