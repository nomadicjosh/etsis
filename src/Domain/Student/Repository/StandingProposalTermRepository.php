<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Bounded calendar references; choosing one does not establish qualifying student work. */
interface StandingProposalTermRepository
{
    public function page(string $search, ?EntityId $after = null): array;
}
