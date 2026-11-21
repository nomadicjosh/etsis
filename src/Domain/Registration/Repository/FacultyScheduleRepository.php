<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface FacultyScheduleRepository
{
    /** Indexed discovery only; callers verify the returned sections' current protected plans.
     * @param list<string> $faculty
     * @return list<string> At most 51 IDs, including the continuation/limit probe.
     */
    public function candidates(array $faculty, string $firstDay, string $lastDay, EntityId $excluding): array;
}
