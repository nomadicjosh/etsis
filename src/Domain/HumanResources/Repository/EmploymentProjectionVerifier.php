<?php

declare(strict_types=1);

namespace Domain\HumanResources\Repository;

use Domain\HumanResources\ValueObject\EmploymentProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted selected-record observations; callers own authorization. Never repairs business records. */
interface EmploymentProjectionVerifier
{
    public function primaryPosition(
        EntityId $employee,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck;
    public function position(
        EntityId $position,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck;
    public function timeEntry(
        EntityId $entry,
        int $eventLimit = 500,
    ): EmploymentProjectionCheck;
}
