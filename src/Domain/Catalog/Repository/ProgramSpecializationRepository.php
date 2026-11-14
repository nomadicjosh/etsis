<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\ProgramSpecialization;
use Domain\Shared\ValueObject\EntityId;

interface ProgramSpecializationRepository
{
    /** @return list<array<string,scalar|null>> At most 21 metadata rows, without descriptions. */
    public function page(EntityId $program, string $search, ?EntityId $after): array;
    public function get(EntityId $program, EntityId $id): ProgramSpecialization;
    /** Active, complete definitions for a reviewed publication; at most 100.
     * @return list<array<string,mixed>> */
    public function offered(EntityId $program): array;
    /** Caller holds the parent program lock, including for new references. */
    public function save(ProgramSpecialization $value, bool $create): void;
}
