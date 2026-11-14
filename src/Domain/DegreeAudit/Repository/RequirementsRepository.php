<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RequirementsRepository
{
    /** @return array<string, mixed> */
    public function context(EntityId $program, EntityId $year): array;
    /** Current reference labels for an authorized authoring scope; publications retain their own labels.
     * @return array{id:string,code:string,title:string} */
    public function specialization(EntityId $program, EntityId $specialization): array;
    public function lockContext(EntityId $program, EntityId $year): void;
    public function lock(EntityId $id): void;
    /** @return array<string, mixed>|null */
    public function find(EntityId $program, EntityId $year, ?EntityId $specialization = null): ?array;
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function page(EntityId $program, ?EntityId $after = null, ?EntityId $specialization = null): array;
    /** @param array<string, mixed> $definition */
    public function create(
        EntityId $id,
        EntityId $program,
        EntityId $year,
        array $definition,
        ?EntityId $specialization = null,
    ): void;
    /** @param array<string, mixed> $definition */
    public function save(EntityId $id, int $expected, array $definition): void;
    public function published(EntityId $id, EntityId $version, int $position): void;
}
