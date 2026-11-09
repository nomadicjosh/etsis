<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Minimal current catalog labels for searching; publication selection supplies authoritative replacement facts. */
interface ReplacementCourseRepository
{
    /** @return list<array<string, mixed>> */
    public function page(string $search, ?EntityId $after): array;
}
