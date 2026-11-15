<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Shared\ValueObject\EntityId;

interface EmergencyContactsRepository
{
    /** @phpstan-impure */
    public function current(EntityId $person): ?EmergencyContacts;
    public function save(EntityId $person, EmergencyContacts $contacts, int $expectedVersion): void;
}
