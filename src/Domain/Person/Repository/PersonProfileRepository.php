<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PersonProfileRepository
{
    public function lock(EntityId $person): void;
    public function save(EntityId $person, string $givenName, string $familyName, bool $directoryRestricted): void;
}
