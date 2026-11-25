<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Advising\BookingPolicyRevision;
use Domain\Shared\ValueObject\EntityId;

interface BookingPolicyRepository
{
    public function current(): ?BookingPolicyRevision;
    public function find(EntityId $id): ?BookingPolicyRevision;
    /** @return list<BookingPolicyRevision> */
    public function page(?int $before = null): array;
    /** Caller holds the existing institutional access lock; revisions are append-only configuration. */
    public function save(BookingPolicyRevision $revision): void;
}
