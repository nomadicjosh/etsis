<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Operational notification references only; this is neither an event journal nor an email queue. */
interface AdmissionUpdatesRepository
{
    public function record(EntityId $application, EntityId $owner, string $at): void;
    /** @return list<array<string,mixed>> */
    public function page(EntityId $owner, bool $unreadOnly, ?string $afterAt = null, ?EntityId $after = null): array;
    public function unreadCount(EntityId $owner): int;
    /** @return array<string,mixed>|null */
    public function owned(EntityId $owner, EntityId $notice): ?array;
    public function lock(EntityId $notice): void;
    public function markRead(EntityId $owner, EntityId $notice, int $revision): void;
    public function delete(EntityId $owner, EntityId $notice, int $revision): bool;
}
