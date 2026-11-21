<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\PersonProjectionCheck;
use Domain\Shared\ValueObject\EntityId;

/** Trusted operators only, including the authorized support facade; never substitutes for profile access. */
interface PersonProjectionVerifier
{
    public function verify(EntityId $person, int $eventLimit = 500): PersonProjectionCheck;
}
