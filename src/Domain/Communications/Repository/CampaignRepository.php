<?php

declare(strict_types=1);

namespace Domain\Communications\Repository;

use Domain\Communications\Campaign;
use Domain\Shared\ValueObject\EntityId;

interface CampaignRepository
{
    public function add(Campaign $campaign): void;
    public function get(EntityId $id): Campaign;
    public function save(Campaign $campaign, int $expectedVersion): void;
    public function lock(EntityId $campaign): void;
    public function enqueue(EntityId $campaign, EntityId $recipient): EntityId;
    /** @return array{campaign_id: string, recipient_user_id: string, status: string, version: int} */
    public function delivery(EntityId $id): array;
    public function transitionDelivery(EntityId $id, string $status, int $expectedVersion): void;
    public function allSent(EntityId $campaign): bool;
    public function recipient(EntityId $id): string;
}
