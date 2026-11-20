<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;

interface EnrollmentLoadPolicyRepository
{
    public function program(EntityId $program): array;
    public function programs(string $search, ?EntityId $after): array;
    public function lockScope(?EntityId $program): void;
    public function current(?EntityId $program): array;
    public function project(?EntityId $program, array $state): void;
}
