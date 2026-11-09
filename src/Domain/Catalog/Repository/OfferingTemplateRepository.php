<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

interface OfferingTemplateRepository
{
    /** @return array<string, mixed>|null */
    public function find(EntityId $id): ?array;
    public function lock(EntityId $id): void;
    public function lockFaculty(EntityId $id): void;
    /** @return list<array<string, mixed>> */
    public function page(?bool $active, string $search, ?EntityId $after, int $limit): array;
    public function save(
        EntityId $id,
        EntityId $actor,
        int $expectedVersion,
        string $name,
        bool $active,
        int $count,
        string $encrypted,
        string $at,
    ): void;
    /** @return array<string, mixed>|null */
    public function operation(EntityId $id): ?array;
    public function record(
        EntityId $operation,
        EntityId $template,
        EntityId $actor,
        int $version,
        string $hash,
        string $encrypted,
        string $at,
    ): void;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $template, int $afterVersion, int $limit): array;
}
