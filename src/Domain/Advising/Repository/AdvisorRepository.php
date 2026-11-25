<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Shared\ValueObject\EntityId;

interface AdvisorRepository
{
    public function rows(EntityId $pursuit): array;
    public function project(EntityId $student, EntityId $pursuit, array $rows): void;
    public function candidate(EntityId $person): bool;
    public function candidates(string $search, ?EntityId $after = null, bool $includeAvailabilityHosts = false): array;
    public function labels(array $persons): array;
    public function mine(EntityId $actor, string $date, string $search, ?EntityId $after = null): array;
}
