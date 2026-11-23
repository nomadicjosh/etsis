<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AdmissionRetentionRepository
{
    public function current(): ?array;
    public function policy(EntityId $id): ?array;
    public function policies(?int $before = null): array;
    public function addPolicy(array $row): void;
    public function classification(EntityId $id): ?array;
    public function classifications(EntityId $document, ?EntityId $before = null): array;
    public function addClassification(array $row): void;
}
