<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Disposable account-pinned notices; encrypted check-ins retain their own working facts. */
interface AdvisingUpdatesRepository
{
    public function replace(array $reference): void;
    public function current(EntityId $checkin): ?array;
    public function respond(EntityId $checkin, EntityId $owner, string $at): void;
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
