<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Minimal current catalog labels for searching; publication selection supplies authoritative replacement facts. */
interface CourseSearchRepository
{
    /** @return list<array<string, mixed>> */
    public function page(string $search, ?EntityId $after = null): array;
}
