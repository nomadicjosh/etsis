<?php

declare(strict_types=1);

namespace Domain\Person\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AccountSetupRepository
{
    /** @param array<string,scalar|null> $row */
    public function stage(array $row): void;
    /** @return array<string,mixed>|null */
    public function byToken(string $hash): ?array;
    /** @return array<string,mixed>|null */
    public function forPerson(EntityId $person): ?array;
    /** @return array<string,mixed> */
    public function locked(EntityId $id): array;
    public function consume(EntityId $id, string $at): void;
    /** @return list<EntityId> */
    public function pending(int $limit): array;
    public function claim(EntityId $id): bool;
    public function delivery(EntityId $id, string $tokenHash, string $state): void;
    /** @param array<string,scalar|null> $changes */
    public function renew(EntityId $id, array $changes): void;
    /** @return list<EntityId> */
    public function retentionCandidates(string $cutoff, ?EntityId $after, int $limit): array;
    /** Bounded binding metadata only; previews do not lock or fetch notification content.
     * @return array<string,mixed>|null */
    public function retentionRecord(EntityId $id, bool $lock = false): ?array;
    public function redactNotice(EntityId $id, string $tokenHash, string $state, string $expiresAt): bool;
}
