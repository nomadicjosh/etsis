<?php

declare(strict_types=1);

namespace Domain\Transfer\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TransferCatalogRepository
{
    /** @return array<string, mixed> */
    public function get(string $kind, EntityId $id): array;
    public function lock(string $kind, EntityId $id): void;
    /** @param array<string, scalar|null> $values */
    public function save(string $kind, EntityId $id, array $values): void;
    public function passingGrade(string $code): EntityId;
    /** @return list<array<string, mixed>> */
    public function children(string $kind, EntityId $id, ?EntityId $after, int $limit): array;
    /** @return list<array<string, mixed>> */
    public function awards(EntityId $id, ?EntityId $after, int $limit): array;
}
