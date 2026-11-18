<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\Shared\ValueObject\EntityId;

interface GradeChangeRepository
{
    public const string POLICY_ID = "01J00000000000000000000GC0";
    /** @return array{mode: string, revision: int} */
    public function policy(bool $current = false): array;
    public function lockPolicy(): void;
    public function savePolicy(string $mode, int $revision): void;
    /** @return array<string, mixed> */
    public function request(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function pending(EntityId $credit): array;
    /** @return list<array<string, mixed>> */
    public function page(?EntityId $after, int $limit): array;
    /** @param array<string, mixed> $record */
    public function project(array $record, ?int $expected): void;
}
