<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Owner-scoped enrollment/teaching projections. Browser-supplied owner or student identities are never accepted. */
interface AcademicTimetableRepository
{
    /** @return list<array<string,mixed>> */
    public function terms(EntityId $owner, string $search, ?EntityId $after): array;

    /** @return array<string,mixed>|null */
    public function recommendedTerm(EntityId $owner, string $today): ?array;

    /** @return array<string,mixed> */
    public function term(EntityId $owner, EntityId $term): array;

    /** @return list<array<string,mixed>> */
    public function sections(EntityId $owner, EntityId $term): array;
}
