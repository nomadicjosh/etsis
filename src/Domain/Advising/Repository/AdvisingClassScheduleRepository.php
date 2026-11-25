<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Internal indexed discovery; verified protected plans remain the source of actual class times. */
interface AdvisingClassScheduleRepository
{
    /** At most 51 active enrollment candidates, including the limit probe, across current pursuits/terms. */
    public function enrollments(EntityId $student, string $firstDay, string $lastDay): array;

    /** At most 51 candidate sections; callers resolve each meeting's retained offering and canonical teacher. */
    public function teaching(EntityId $person, EntityId $account, string $firstDay, string $lastDay): array;
}
