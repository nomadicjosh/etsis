<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PersonSsnRepository
{
    public function find(EntityId $person): ?array;
    public function save(EntityId $person, int $expectedVersion, #[\SensitiveParameter] string $sealed): void;
}
