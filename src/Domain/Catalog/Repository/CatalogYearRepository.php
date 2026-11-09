<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\CatalogYear;
use Domain\Shared\ValueObject\EntityId;

interface CatalogYearRepository
{
    public function derived(EntityId $program, EntityId $term): ?EntityId;
    public function lockYear(EntityId $id): void;
    public function lockProgram(EntityId $id): void;
    public function year(EntityId $id): CatalogYear;
    /** @return array<string, mixed> */
    public function release(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function releases(EntityId $program, ?EntityId $after, bool $publishedOnly = false): array;
    public function nextRevision(EntityId $program, EntityId $year): int;
    /** @param array<string, scalar> $data */
    public function addRelease(EntityId $id, array $data): void;
    public function publishRelease(EntityId $id, EntityId $program): void;
    /** @return array<string, mixed>|null */
    public function binding(EntityId $assignment): ?array;
    public function bind(EntityId $assignment, EntityId $catalog, int $expectedVersion): void;
    /** @return array<string, mixed>|null */
    public function action(EntityId $operation): ?array;
    public function record(
        EntityId $operation,
        EntityId $assignment,
        EntityId $actor,
        ?EntityId $previous,
        EntityId $catalog,
        ?string $encryptedNote,
        string $on,
    ): void;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $assignment, ?EntityId $after): array;
}
