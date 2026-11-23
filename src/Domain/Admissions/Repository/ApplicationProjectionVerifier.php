<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\ValueObject\ApplicationProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Operator read; browser callers require the independently authorized support facade. */
interface ApplicationProjectionVerifier
{
    public function verify(EntityId $application, int $eventLimit = 500): ApplicationProjectionCheck;
}
