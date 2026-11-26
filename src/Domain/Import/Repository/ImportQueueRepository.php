<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Shared\ValueObject\EntityId;

interface ImportQueueRepository
{
    /** @param array<string,scalar|null> $job */
    public function stage(array $job, string $encrypted, string $hash): void;
    /** @return array<string,mixed>|null */
    public function job(EntityId $job): ?array;
    /** @return array<string,mixed>|null */
    public function artifact(EntityId $job): ?array;
    /** @param array<string,scalar|null> $changes */
    public function update(EntityId $job, array $changes): void;
    /** @return list<array<string,mixed>> */
    public function page(EntityId $owner, ?EntityId $after = null): array;
    /** @return list<array<string,mixed>> */
    public function pending(string $now): array;
    public function claim(
        EntityId $job,
        int $revision,
        string $token,
        string $until,
    ): bool;
    public function purgeArtifact(EntityId $job): void;
    /** @param array<string,scalar|null> $notice */
    public function notify(array $notice): void;
    /** @return list<array<string,mixed>> */
    public function notices(
        EntityId $owner,
        bool $unread,
        ?EntityId $after = null,
    ): array;
    public function readNotice(
        EntityId $owner,
        EntityId $notice,
        string $at,
    ): void;
    public function deleteNotice(EntityId $owner, EntityId $notice): void;
    /** @return list<array<string,mixed>> */
    public function abandoned(string $now): array;
    public function discardReview(EntityId $job): void;
}
