<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface SectionEnrollmentReviewRepository
{
    public function page(
        string $state,
        string $search,
        bool $includeInactive,
        ?EntityId $term = null,
        ?EntityId $after = null,
    ): array;
}
