<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

interface CatalogRevisionRepository
{
    /** @return array<string, scalar|null> */
    public function get(string $kind, EntityId $id): array;
    public function lock(string $kind, EntityId $id): void;
    /** @param array<string, scalar|null> $values */
    public function save(string $kind, EntityId $id, array $values): void;
}
