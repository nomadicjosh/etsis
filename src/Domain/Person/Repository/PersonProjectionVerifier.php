<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\PersonProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted maintenance operators only; never a substitute for profile read authorization. */
interface PersonProjectionVerifier
{
    public function verify(EntityId $person, int $eventLimit = 500): PersonProjectionCheck;
}
