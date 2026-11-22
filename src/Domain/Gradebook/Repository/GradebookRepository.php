<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Shared\ValueObject\EntityId;

interface GradebookRepository
{
    /** @return array{grade_policy: array|null, mode: string, weights: array<string, int>, revision: int, categories: list<array{title: string, weight_hundredths: int, assignments: list<string>}>} */
    public function calculation(EntityId $section): array;
    /** @param array<string, int> $weights
     * @param list<array{title: string, weight_hundredths: int, assignments: list<string>}> $categories
     */
    public function saveCalculation(
        EntityId $section,
        string $mode,
        array $weights,
        int $revision,
        array $categories,
        ?array $gradePolicy,
    ): void;
    /** @return list<array<string, mixed>> */
    public function assignments(EntityId $section, int $limit): array;
    /** Classify only the configured weighted IDs, without loading an entire gradebook.
     * @param list<string> $ids
     * @return list<string>
     */
    public function bonusAssignmentIds(EntityId $section, array $ids): array;
    public function addAssignment(Assignment $assignment): void;
    public function assignment(EntityId $id): Assignment;
    public function saveAssignment(Assignment $assignment): void;
    public function highestScore(EntityId $assignment): ?int;
    /** Active enrollment and optional saved score, bounded to one assignment and one page.
     * @return list<array<string, mixed>>
     */
    public function scoreRoster(
        Assignment $assignment,
        string $search,
        string $state,
        ?EntityId $after,
        int $limit,
    ): array;
    /** @return list<array<string,mixed>> */
    public function attendanceRoster(
        EntityId $section,
        string $on,
        string $search,
        string $state,
        ?EntityId $after,
        int $limit,
    ): array;
    /** @return array<string, mixed>|null */
    public function attendanceRecord(EntityId $section, EntityId $student, string $on): ?array;
    /** @return list<array<string, mixed>> */
    public function attendancePage(EntityId $section, EntityId $student, ?EntityId $after, int $limit): array;
    /** @return array<string, int> */
    public function attendanceCounts(EntityId $section, EntityId $student): array;
    public function score(AssignmentScore $score, int $expectedVersion): void;
    /** @return array{earned_hundredths:?int,version:int,excused:bool}|null */
    public function scoreRecord(EntityId $assignment, EntityId $student): ?array;

    public function attendance(
        EntityId $section,
        EntityId $student,
        string $on,
        string $status,
        int $expectedVersion,
    ): void;
    /** @return list<array<string, mixed>> */
    public function scores(
        EntityId $student,
        EntityId $section,
        ?EntityId $after = null,
        ?int $limit = null,
    ): array;
}
