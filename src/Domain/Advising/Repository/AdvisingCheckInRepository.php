<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Advising\AdvisingCheckIn;
use Domain\Shared\ValueObject\EntityId;

interface AdvisingCheckInRepository
{
    public function find(EntityId $student, EntityId $pursuit, EntityId $id): ?AdvisingCheckIn;
    public function activeCount(EntityId $student, EntityId $pursuit): int;
    /** @return list<AdvisingCheckIn> */
    public function page(
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
    ): array;
    public function save(AdvisingCheckIn $checkIn, ?int $expectedVersion): void;
}
