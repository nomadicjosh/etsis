<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface HiatusImpactRepository
{
    /** @return list<array<string, mixed>> */
    public function enrollments(EntityId $student, string $effectiveDate): array;
    public function lockTerm(EntityId $term): void;
}
