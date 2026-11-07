<?php

declare(strict_types=1);

namespace Domain\Audit\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AuditTrail
{
    public function record(EntityId $actor, string $action, EntityId $subject): void;

    /** @return list<array<string, mixed>> */
    public function forSubject(EntityId $subject): array;
}
