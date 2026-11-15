<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\Person;
use Domain\Shared\ValueObject\EntityId;

interface PersonAccountRepository
{
    /** @phpstan-impure */
    public function findByUser(EntityId $user): ?Person;
    public function emailInUse(string $email): bool;
    public function assignInitialIdentifier(EntityId $person, \Domain\Person\ValueObject\InstitutionalId $id): void;
    public function ensure(EntityId $user, string $givenName, string $familyName, ?EntityId $person = null): Person;
    public function updateNames(EntityId $user, string $givenName, string $familyName): void;
}
