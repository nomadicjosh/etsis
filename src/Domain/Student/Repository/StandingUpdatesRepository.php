<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Disposable references to accepted standing; no reasons, decisions or historical archive. */
interface StandingUpdatesRepository
{
    public function replace(array $reference): void;
    public function current(EntityId $pursuit): ?array;
    public function page(
        EntityId $owner,
        EntityId $person,
        bool $unreadOnly,
        ?string $afterAt = null,
        ?EntityId $after = null,
    ): array;
    public function owned(EntityId $owner, EntityId $person, EntityId $notice): ?array;
    public function lock(EntityId $notice): void;
    public function delete(EntityId $owner, EntityId $person, EntityId $notice): void;
    public function markRead(EntityId $owner, EntityId $notice, string $at): void;
}
