<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\Person;
use Domain\Shared\ValueObject\EntityId;

interface PersonAccountRepository
{
    public function ensure(EntityId $user, string $givenName, string $familyName, ?EntityId $person = null): Person;
    public function updateNames(EntityId $user, string $givenName, string $familyName): void;
}
