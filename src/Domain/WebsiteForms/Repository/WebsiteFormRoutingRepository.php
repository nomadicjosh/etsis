<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface WebsiteFormRoutingRepository
{
    public function permits(EntityId $user, string $kind, string $scope, string $key): bool;
    public function departments(string $search, ?EntityId $after = null): array;
    public function pages(string $search, ?string $after = null): array;
    public function forms(string $page): array;
    public function members(string $kind, string $scope, string $key, ?EntityId $after = null): array;
    public function grant(string $kind, string $scope, string $key, EntityId $user): ?array;
    public function save(string $kind, string $scope, string $key, EntityId $user, bool $grant): EntityId;
}
