<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface DegreeProgressRecordRepository
{
    public function find(EntityId $id): ?array;
    public function insert(array $row): void;
    public function page(EntityId $student, EntityId $pursuit, ?EntityId $after): array;
}
