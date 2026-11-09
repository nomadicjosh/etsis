<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RequirementsRepository
{
    /** @return array<string, mixed> */
    public function context(EntityId $program, EntityId $year): array;
    public function lockContext(EntityId $program, EntityId $year): void;
    public function lock(EntityId $id): void;
    /** @return array<string, mixed>|null */
    public function find(EntityId $program, EntityId $year): ?array;
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function page(EntityId $program, ?EntityId $after): array;
    /** @param array<string, mixed> $definition */
    public function create(EntityId $id, EntityId $program, EntityId $year, array $definition): void;
    /** @param array<string, mixed> $definition */
    public function save(EntityId $id, int $expected, array $definition): void;
    public function published(EntityId $id, EntityId $version, int $position): void;
}
