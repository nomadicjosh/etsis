<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface DropPolicyRepository
{
    /** @return array<string, mixed> */
    public function calendar(EntityId $term, ?EntityId $section = null): array;
    public function lockTerm(EntityId $term, ?EntityId $section = null): void;
    /** @return array<string, mixed>|null */
    public function find(EntityId $term, ?EntityId $section = null): ?array;
    /** @param list<array{through: ?string, grade_code: string}> $windows
     * @param array<string,string> $calendar */
    public function save(
        EntityId $term,
        int $version,
        array $windows,
        EntityId $publication,
        array $calendar,
        ?EntityId $section = null,
    ): void;
    /** @return array<string, mixed>|null */
    public function grade(string $code, ?EntityId $student = null): ?array;
}
