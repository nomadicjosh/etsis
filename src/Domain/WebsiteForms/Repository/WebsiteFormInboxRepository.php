<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface WebsiteFormInboxRepository
{
    public function receipt(EntityId $id): ?array;
    public function add(array $row, array $receipt): void;
    public function recent(string $page, string $sessionHash, string $since): int;
    public function prune(string $before, int $limit = 100): int;
    public function page(
        EntityId $actor,
        ?EntityId $after = null,
        string $state = 'all',
        string $scope = 'all',
        ?EntityId $person = null,
    ): array;
    public function assignment(EntityId $id): ?array;
    public function assign(EntityId $id, int $version, ?EntityId $user, ?EntityId $person, string $at): void;
    public function assignees(EntityId $id, string $search, ?EntityId $after = null): array;
    public function visible(EntityId $actor, EntityId $id): bool;
    public function get(EntityId $id): ?array;
    public function lock(EntityId $id): void;
    public function update(EntityId $id, int $version, string $status): void;
    public function delete(EntityId $id, int $version): void;
}
