<?php

declare(strict_types=1);

namespace Domain\Office\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Audited work routing and access relationships; these are not academic decisions. */
interface OfficeRepository
{
    /** @return array<string,mixed>|null */
    public function office(EntityId $id): ?array;
    public function lock(EntityId $id): void;
    /** @param array<string,scalar|null> $facts */
    public function saveOffice(array $facts, bool $new): void;
    /** @return list<array<string,mixed>> */
    public function offices(?EntityId $user, string $on, int $limit): array;
    /** @return array<string,mixed>|null */
    public function delegation(EntityId $office, EntityId $user): ?array;
    /** @return list<array<string,mixed>> */
    public function delegates(EntityId $office, ?EntityId $after, int $limit): array;
    /** @param array<string,scalar|null> $facts */
    public function saveDelegation(array $facts, bool $new): void;
    public function userEnabled(EntityId $user): bool;
    /** @return list<array<string,mixed>> */
    public function people(string $search, bool $accountsOnly, int $limit): array;
    /** @return array<string,mixed>|null */
    public function owner(EntityId $restriction): ?array;
    public function bind(EntityId $restriction, EntityId $office): void;
    public function move(EntityId $restriction, EntityId $from, EntityId $to): void;
    /** @return array<string,mixed>|null */
    public function followUp(EntityId $restriction): ?array;
    public function saveFollowUp(
        EntityId $restriction,
        EntityId $office,
        ?EntityId $assignee,
        ?string $on,
        int $revision,
    ): void;
    public function userCanManageHolds(EntityId $user): bool;
    /** @return list<array<string,mixed>> */
    public function workers(EntityId $office, string $on, string $search, ?EntityId $after, int $limit): array;
}
