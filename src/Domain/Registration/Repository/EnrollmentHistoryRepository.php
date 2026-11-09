<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface EnrollmentHistoryRepository
{
    /** @return array<string,mixed>|null */
    public function mapping(EntityId $id): ?array;
    /** @return array<string,mixed>|null */
    public function current(EntityId $id): ?array;
    /** @return list<array<string,mixed>> */
    public function decisions(EntityId $enrollment): array;
    /** @return array<string,mixed>|null */
    public function latest(EntityId $section): ?array;
    public function maximumSequence(EntityId $section): int;
    public function usedSeats(EntityId $section): int;
    public function termCode(EntityId $term): string;
    /** @param array<string,mixed> $facts */
    public function project(array $facts, int $sequence, ?int $expectedVersion): void;
    /** @param array<string,mixed> $decision */
    public function index(array $decision): void;
    /** @return list<array<string,mixed>> */
    public function forStudent(EntityId $student, ?EntityId $after, int $limit): array;
}
