<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Shared\ValueObject\EntityId;

interface NotificationPreferencesRepository
{
    public function current(EntityId $person): ?array;
    public function save(EntityId $person, array $preferences, int $revision): void;
}
