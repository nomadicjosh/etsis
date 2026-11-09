<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Registration\Enrollment;
use Domain\Shared\ValueObject\EntityId;

interface CourseDropRepository
{
    /** @return array<string, mixed> */
    public function credit(Enrollment $enrollment): array;
    /** @param array<string, mixed> $credit
     * @param array<string, mixed> $outcome
     */
    public function apply(Enrollment $closed, int $version, array $credit, array $outcome): void;
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
