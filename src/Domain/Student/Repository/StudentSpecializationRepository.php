<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface StudentSpecializationRepository
{
    /** @return array{payload:array<string,mixed>,position:int}|null */
    public function get(EntityId $assignment): ?array;
    /** Caller holds the student lock in the event/audit transaction.
     * @param array<string,mixed> $payload */
    public function project(EntityId $assignment, array $payload, int $position): void;
    /** @return array<string,array<string,mixed>> */
    public function requirements(EntityId $assignment): array;
    /** Caller owns the same student lock and journal transaction as project().
     * @param array<string,array<string,mixed>> $requirements */
    public function projectRequirements(EntityId $assignment, array $requirements): void;
}
