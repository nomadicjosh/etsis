<?php

declare(strict_types=1);

namespace Domain\Access\Repository;

use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Shared\ValueObject\EntityId;

interface AccessRepository
{
    public function allows(string $userId, string $permission): bool;
    /**
     * @param list<EntityId> $users
     * @return list<string> Allowed account IDs, bounded to twenty candidates.
     */
    public function allowedUsers(array $users, string $permission): array;
    /** @param list<string> $permissions All required duties, filtered before paging.
     * @return list<array{id:string,person_id:string,given_name:string,family_name:string,institutional_id:string}> */
    public function eligibleAccounts(array $permissions, string $search, ?EntityId $after = null): array;
    public function anyoneCanManage(): bool;
    public function isSuper(string $userId): bool;
    public function anySuper(): bool;
    public function lock(): void;
    public function requireUser(EntityId $user): void;
    public function roleNamed(string $name): AccessDefinition;
    public function definition(DefinitionKind $kind, EntityId $id): AccessDefinition;
    public function updateDescription(DefinitionKind $kind, EntityId $id, string $description): void;
    public function remove(DefinitionKind $kind, EntityId $id): void;
    public function add(DefinitionKind $kind, AccessDefinition $definition): void;

    /** @return list<AccessDefinition> */
    public function definitions(DefinitionKind $kind, ?EntityId $after, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function directory(
        DefinitionKind $kind,
        string $search,
        ?EntityId $after,
        int $limit,
        ?GrantKind $grant,
        ?EntityId $subject,
        bool $assignedOnly
    ): array;

    /** @return list<string> Target IDs, ordered and bounded. */
    public function assignments(GrantKind $kind, EntityId $subject, ?EntityId $after, int $limit): array;

    public function assign(GrantKind $kind, EntityId $subject, EntityId $target, bool $grant): void;
    public function assignDefaultRole(string $userId): void;
    public function removeUserAssignments(string $userId): void;
}
