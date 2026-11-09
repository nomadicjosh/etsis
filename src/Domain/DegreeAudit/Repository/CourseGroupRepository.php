<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface CourseGroupRepository
{
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    public function lock(EntityId $id): void;
    /** @return list<array<string, mixed>> */
    public function page(string $search, ?EntityId $after, bool $publishedOnly): array;
    /** @param array<string, mixed> $definition */
    public function create(EntityId $id, array $definition): void;
    /** @param array<string, mixed> $definition */
    public function save(EntityId $id, int $revision, array $definition): void;
    public function published(EntityId $id, EntityId $version, int $position, string $name): void;
}
