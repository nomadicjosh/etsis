<?php

declare(strict_types=1);

namespace Domain\Tuition\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TuitionRepository
{
    public function sectionLabel(EntityId $enrollment): string;
    public function earlierRegistration(
        EntityId $student,
        EntityId $term,
        EntityId $current,
    ): bool;
    public function find(string $kind, EntityId $id): ?array;
    public function save(
        string $kind,
        EntityId $id,
        array $values,
        int $expected,
    ): void;
    public function schedules(?EntityId $after = null): array;
    public function bills(
        bool $pending,
        ?EntityId $owner = null,
        ?EntityId $after = null,
    ): array;
    public function addInvoice(array $values): void;
    public function invoice(EntityId $id): ?array;
    public function termLabel(EntityId $id): string;
    public function account(EntityId $student, EntityId $term): ?array;
    public function scopeLabel(string $scope, EntityId $id): string;
}
