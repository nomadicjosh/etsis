<?php

declare(strict_types=1);

namespace Domain\Communications\Repository;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;

interface CampaignDispatchRepository
{
    /** Protected authorization references, never a browser response.
     * @return array<string,mixed>|null
     */
    public function get(EntityId $campaign): ?array;

    /** Caller holds the existing campaign lock.
     * @param array<string,mixed> $record
     */
    public function save(array $record, int $expectedVersion): void;

    /** @return list<array<string,mixed>> */
    public function pending(DateTimeImmutable $dueThrough): array;
}
