<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface StudentStandingRepository
{
    public function definitions(string $search, ?EntityId $after, bool $activeOnly): array;
    public function definition(EntityId $id): array;
    /** @param list<string> $ids At most twenty-five identities; ordinary read without source locks. */
    public function definitionLabels(array $ids): array;
    public function lockDefinition(EntityId $id): void;
    public function saveDefinition(
        EntityId $id,
        string $code,
        string $title,
        bool $active,
        ?int $version,
    ): array;
    public function rows(EntityId $student, EntityId $pursuit): array;
    public function project(EntityId $student, EntityId $pursuit, array $rows): void;
}
