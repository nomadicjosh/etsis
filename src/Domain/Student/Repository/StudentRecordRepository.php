<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;

interface StudentRecordRepository
{
    public function lock(EntityId $student): void;
    /** @return array{id: string, code: string, title: string} */
    public function program(EntityId $program): array;
    /** @return array{id: string, code: string} */
    public function term(EntityId $term): array;
    /** @return array<string, mixed>|null */
    public function primaryCatalog(EntityId $student): ?array;
    public function assignment(EntityId $assignment): StudentProgram;
    public function addAssignment(StudentProgram $assignment): void;
    /** @return list<array<string, mixed>> */
    public function assignments(EntityId $student, ?EntityId $after, int $limit): array;
}
