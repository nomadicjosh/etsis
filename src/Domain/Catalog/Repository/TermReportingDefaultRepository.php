<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TermReportingDefaultRepository
{
    /** @return array{reporting_group_id: ?string, version: int} */
    public function current(EntityId $term): array;

    public function save(EntityId $term, ?EntityId $group, int $version): void;
}
