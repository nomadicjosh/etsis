<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface SectionChangeSearch
{
    /** @return list<array<string,mixed>> At most twenty-one current reference rows. */
    public function page(
        EntityId $course,
        EntityId $term,
        EntityId $from,
        string $search,
        ?EntityId $after = null,
    ): array;
}
