<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RequirementAdjustmentRepository
{
    /** @return array<string, array<string, mixed>> */
    public function active(EntityId $assignment): array;
    /** @param array<string, mixed> $payload */
    public function grant(EntityId $assignment, array $payload): void;
    public function revoke(EntityId $assignment, EntityId $id): void;
}
