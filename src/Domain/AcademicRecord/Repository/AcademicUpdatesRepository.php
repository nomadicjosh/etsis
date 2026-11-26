<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Disposable latest-grade notices; protected academic decisions remain authoritative. */
interface AcademicUpdatesRepository
{
    public function replace(array $reference): void;
    public function current(EntityId $credit): ?array;
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
