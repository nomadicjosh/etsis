<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface EnrollmentLoadRepository
{
    public function lockTerm(EntityId $term): void;
    public function terms(string $search, ?EntityId $after): array;
    public function term(EntityId $term): array;
    /** Includes known closed identities for verification; those must never enter the public result. */
    public function inventory(EntityId $student, EntityId $term): array;
}
