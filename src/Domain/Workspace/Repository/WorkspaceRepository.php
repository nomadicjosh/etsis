<?php

declare(strict_types=1);

namespace Domain\Workspace\Repository;

use Domain\Shared\ValueObject\EntityId;

interface WorkspaceRepository
{
    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function records(string $dataset, ?EntityId $owner, string $search, ?EntityId $after): array;
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function metrics(array $permissions): array;
    /**
     * @param list<string> $sections
     * @return array<string, mixed>
     */
    public function personWorkspace(EntityId $person, array $sections): array;
    /** @return array<string, mixed> */
    public function ownPerson(EntityId $user): array;
}
