<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\ValueObject\EntityId;

interface GradebookRepository
{
    public function addGrade(GradeDefinition $grade): void;
    public function grade(EntityId $id): GradeDefinition;
    public function addAssignment(Assignment $assignment): void;
    public function assignment(EntityId $id): Assignment;
    public function saveAssignment(Assignment $assignment): void;
    public function highestScore(EntityId $assignment): ?int;
    /** @return array<string, mixed>|null */
    public function attendanceRecord(EntityId $section, EntityId $student, string $on): ?array;
    /** @return list<array<string, mixed>> */
    public function attendancePage(EntityId $section, EntityId $student, ?EntityId $after, int $limit): array;
    /** @return array<string, int> */
    public function attendanceCounts(EntityId $section, EntityId $student): array;
    public function score(AssignmentScore $score, int $expectedVersion): void;
    public function finalGrade(EntityId $enrollment, GradeDefinition $grade, int $expectedVersion): void;
    public function attendance(EntityId $section, EntityId $student, string $on, string $status): void;
    /** @return list<array<string, mixed>> */
    public function scores(EntityId $student, EntityId $section, ?EntityId $after = null, ?int $limit = null): array;
}
