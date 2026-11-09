<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

use Domain\Shared\ValueObject\EntityId;

interface GradingScaleRepository
{
    /** @return list<array<string,mixed>> */
    public function grades(EntityId $version): array;
    public function lockScope(?EntityId $program): void;
    public function requireProgram(EntityId $program): void;
    /** @return array<string,mixed>|null */
    public function current(?EntityId $program): ?array;
    /** @param array<string,mixed>|null $definition */
    public function save(?EntityId $program, EntityId $version, int $position, ?array $definition): void;
}
