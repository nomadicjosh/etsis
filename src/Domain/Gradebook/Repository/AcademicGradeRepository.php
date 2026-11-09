<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AcademicGradeRepository
{
    public function hasPursuit(EntityId $student): bool;
    /** @return array<string,mixed> */
    public function grade(EntityId $id): array;
    /** @return list<array<string,mixed>> */
    public function legacyGrades(): array;
    /** @return list<array<string,mixed>> */
    public function pursuits(EntityId $student): array;
}
