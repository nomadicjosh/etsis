<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Trusted operator boundary: one bounded record, fixed status/references only, no business decision. */
interface AcademicDateReconciliation
{
    public function run(?EntityId $credit, ?EntityId $after, bool $apply, int $eventLimit = 500): array;
}
