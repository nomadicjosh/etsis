<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\PersonSsnProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted operators and the independently authorized support facade only. */
interface PersonSsnProjectionVerifier
{
    public function verify(EntityId $person, int $eventLimit = 500): PersonSsnProjectionCheck;
}
