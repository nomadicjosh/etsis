<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface DropPolicyRepository
{
    /** @return array<string, mixed> */
    public function calendar(EntityId $term): array;
    public function lockTerm(EntityId $term): void;
    /** @return array<string, mixed>|null */
    public function find(EntityId $term): ?array;
    /** @param list<array{through: ?string, grade_code: string}> $windows */
    public function save(EntityId $term, int $version, array $windows): void;
    /** @return array<string, mixed>|null */
    public function grade(string $code, ?EntityId $student = null): ?array;
}
