<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\Enrollment;
use Domain\Shared\ValueObject\EntityId;

interface CourseDropRepository
{
    /** @return list<array<string,mixed>> */
    public function choices(EntityId $student, string $search, ?EntityId $after = null): array;
    /** @return array<string, mixed> */
    public function credit(Enrollment $enrollment): array;
    /** @return array<string, mixed>|null */
    public function receipt(EntityId $operation): ?array;
    public function record(
        EntityId $operation,
        EntityId $student,
        EntityId $section,
        EntityId $actor,
        EntityId $enrollment,
        int $version,
        string $hash,
        string $encrypted,
        string $createdAt,
    ): void;
}
