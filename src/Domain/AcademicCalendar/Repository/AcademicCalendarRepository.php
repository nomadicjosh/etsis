<?php

declare(strict_types=1);

namespace Domain\AcademicCalendar\Repository;

use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Shared\ValueObject\EntityId;

interface AcademicCalendarRepository
{
    public function lockCalendar(): void;
    public function touch(): void;
    public function revision(): int;
    /** @return list<array<string, mixed>> */
    public function types(?EntityId $after, bool $activeOnly = false): array;
    public function type(EntityId $id): TermType;
    public function saveType(TermType $type, int $expectedVersion): void;
    /** @return list<array<string, mixed>> */
    public function years(?EntityId $after): array;
    /** @return array<string, mixed> */
    public function year(EntityId $id): array;
    /** @return array<string, mixed>|null */
    public function yearByStart(int $start): ?array;
    /** @param array<string, mixed> $year */
    public function addYear(array $year): void;
    /** @return array<string, mixed>|null */
    public function termByCode(string $code): ?array;
    /** @return array<string, mixed> */
    public function term(EntityId $id): array;
    /** @param array<string, mixed> $term */
    public function saveTerm(EntityId $id, ?EntityId $year, array $term, bool $insert): void;
    /**
     * @param list<string> $excluding
     * @return list<array<string, mixed>>
     */
    public function overlaps(string $start, string $end, ?EntityId $except, array $excluding = []): array;
    /** @return array<string, mixed>|null */
    public function operation(EntityId $id): ?array;
    public function record(
        EntityId $id,
        EntityId $year,
        EntityId $actor,
        string $hash,
        string $encrypted,
        string $at,
    ): void;
}
