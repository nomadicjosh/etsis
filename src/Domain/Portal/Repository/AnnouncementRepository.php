<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AnnouncementRepository
{
    /** @return list<array<string, mixed>> */
    public function page(EntityId $owner, bool $unreadOnly, ?EntityId $after): array;

    public function available(EntityId $post): bool;

    /** @return array<string, mixed>|null */
    public function readState(EntityId $owner, EntityId $post): ?array;

    public function markRead(EntityId $owner, EntityId $post, int $revision, string $at): void;
}
