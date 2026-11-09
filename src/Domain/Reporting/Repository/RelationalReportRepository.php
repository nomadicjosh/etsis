<?php

declare(strict_types=1);

namespace Domain\Reporting\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RelationalReportRepository
{
    /** @return array<string, mixed>|null */
    public function get(EntityId $id): ?array;
    /** @return array<string, mixed>|null */
    public function operation(EntityId $id): ?array;
    public function save(EntityId $id, string $name, string $encrypted, int $expectedVersion): void;
    public function record(
        EntityId $operation,
        EntityId $report,
        EntityId $actor,
        int $version,
        string $encrypted,
        string $on,
    ): void;
}
