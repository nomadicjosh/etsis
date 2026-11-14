<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Shared\ValueObject\EntityId;

interface ProgramDefinitionRepository
{
    /** @return array<string, mixed> */
    public function draft(EntityId $program): array;
    /** @param array<string, ?EntityId> $choices */
    public function saveDraft(EntityId $program, ?string $description, array $choices): void;
    public function lockYear(EntityId $year): void;
    /** @return array<string, mixed>|null */
    public function current(EntityId $program, EntityId $year): ?array;
    /** @return array<string, mixed>|null */
    public function retained(EntityId $version): ?array;
    public function retain(
        EntityId $program,
        EntityId $version,
        string $yearCode,
        int $position,
        ProgramDefinition $definition,
        \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot $specializations,
    ): void;
}
