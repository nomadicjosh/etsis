<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AcademicCreditRepository
{
    /** @return array<string,mixed>|null */
    public function mapping(EntityId $credit): ?array;
    /** @return array<string,mixed>|null */
    public function forEnrollment(EntityId $enrollment): ?array;
    /** @return array<string,mixed>|null */
    public function projection(EntityId $credit): ?array;
    /** @param array<string,mixed> $facts */
    public function project(array $facts, EntityId $section, ?int $expectedVersion): void;
    /** @return array<string,mixed> */
    public function section(EntityId $section): array;
    /** @return array<string,mixed>|null */
    public function enrollment(EntityId $enrollment): ?array;
    public function lockStudent(EntityId $student): void;
    public function lockSection(EntityId $section): void;
}
