<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Person\ValueObject\AddressBook;
use Domain\Shared\ValueObject\EntityId;

interface AddressBookRepository
{
    /** @phpstan-impure */
    public function current(EntityId $person): ?AddressBook;
    public function save(EntityId $person, AddressBook $book, int $expectedVersion): void;
}
