<?php

declare(strict_types=1);

namespace Domain\Advising\Repository;

use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AppointmentTime;
use Domain\Shared\ValueObject\EntityId;

interface AdvisingAvailabilityRepository
{
    public function find(EntityId $id): ?AdvisingAvailability;
    /** @return list<AdvisingAvailability> */
    public function page(EntityId $host, ?EntityId $account, string $now, bool $finished, ?EntityId $after): array;
    /** @return list<AdvisingAvailability> */
    public function on(EntityId $host, string $fromUtc, string $throughUtc): array;
    public function activeCount(EntityId $host, string $now): int;
    public function conflict(EntityId $host, AppointmentTime $time, ?EntityId $except = null): bool;
    public function save(AdvisingAvailability $item, ?int $version): void;
}
