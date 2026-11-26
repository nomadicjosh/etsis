<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

interface ReportingHierarchyRepository
{
    public const STATE_ID = "01J00000000000000000000018";

    public function lock(): void;
    public function version(): int;
    public function advance(int $expected): void;
    public function parent(EntityId $group): ?EntityId;
    public function peekParent(EntityId $group): ?EntityId;
    public function assign(EntityId $group, ?EntityId $parent): void;
    /** @return array<string, ?string> All explicit relationships, bounded to 500 nodes. */
    public function relations(): array;
}
