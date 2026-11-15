<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\ContactDetails;
use Domain\Shared\ValueObject\EntityId;

interface PersonContactRepository
{
    /** @phpstan-impure */
    public function current(EntityId $person): ?ContactDetails;
    public function save(EntityId $person, ContactDetails $contact, int $expectedVersion): void;
}
