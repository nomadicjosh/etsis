<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentHiatus;

interface HiatusRepository
{
    public function lockStudent(EntityId $student): void;
    public function lock(EntityId $id): void;
    public function find(EntityId $id): ?StudentHiatus;
    public function add(StudentHiatus $hiatus): void;
    public function save(StudentHiatus $hiatus, int $expectedVersion): void;
    public function overlaps(StudentHiatus $hiatus): bool;
    /** @return list<StudentHiatus> */
    public function page(EntityId $student, ?EntityId $after, int $limit): array;
    /** @return array<string, mixed>|null */
    public function action(EntityId $id): ?array;
    /** @return array<string, mixed>|null */
    public function returned(EntityId $hiatus): ?array;
    public function addAction(
        EntityId $id,
        EntityId $hiatus,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] string $encrypted,
        string $on,
    ): void;
    /** @return list<array<string, mixed>> */
    public function history(EntityId $hiatus, ?EntityId $after, int $limit): array;
}
