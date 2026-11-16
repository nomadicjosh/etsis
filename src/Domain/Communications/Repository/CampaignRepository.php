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
    /** Owner-scoped reusable messages, one page plus a continuation probe.
     * @return list<Campaign>
     */
    public function templates(EntityId $owner, bool $retired, ?EntityId $after = null): array;
    /** Caller holds the draft campaign lock and has validated the distinct, bounded audience.
     * @param list<EntityId> $recipients
     * @return list<EntityId>
     */
    public function enqueue(EntityId $campaign, array $recipients): array;
    /** @return array{campaign_id: string, recipient_user_id: string, status: string, version: int} */
    public function delivery(EntityId $id): array;
    public function transitionDelivery(EntityId $id, string $status, int $expectedVersion): void;
    public function allSent(EntityId $campaign): bool;
    public function recipient(EntityId $id): string;

    /** Minimal person/account labels; one page plus a continuation probe.
     * @return list<array<string,string|null>>
     */
    public function recipientChoices(string $search, ?EntityId $after = null): array;

    /** Validate the complete selected audience without loading private profiles or returning email addresses.
     * @param list<EntityId> $users
     * @return list<array<string,string|null>>
     */
    public function audience(array $users): array;
}
