<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Advising\AdvisingAvailabilitySeries;
use Domain\Shared\ValueObject\EntityId;

interface AdvisingAvailabilitySeriesRepository
{
    public function find(EntityId $id): ?AdvisingAvailabilitySeries;
    /** @return list<AdvisingAvailabilitySeries> */
    public function page(EntityId $host, ?EntityId $account, ?EntityId $after): array;
    public function save(AdvisingAvailabilitySeries $item, ?int $version): void;
}
