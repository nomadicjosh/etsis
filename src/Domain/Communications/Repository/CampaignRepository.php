<?php

declare(strict_types=1);

namespace Domain\Communications\Repository;

use Domain\Communications\Campaign;
use Domain\Communications\Enum\RecordRecipientSource;
use Domain\Communications\Enum\DeliveryReviewDecision;
use Domain\Communications\Enum\TemplateLibraryScope;
use Domain\Shared\ValueObject\EntityId;

interface CampaignRepository
{
    public function add(Campaign $campaign): void;
    public function get(EntityId $id): Campaign;
    public function save(Campaign $campaign, int $expectedVersion): void;
    public function lock(EntityId $campaign): void;
    /** Scoped reusable messages, one page plus a continuation probe; callers authorize the scope.
     * @return list<Campaign>
     */
    public function templates(EntityId $actor, TemplateLibraryScope $scope, ?EntityId $after = null): array;
    /** Caller holds the draft campaign lock and has validated the distinct, bounded audience.
     * @param list<EntityId> $recipients
     * @return list<EntityId>
     */
    public function enqueue(EntityId $campaign, array $recipients): array;
    /** @return array{campaign_id:string,recipient_user_id:string,status:string,version:int,
     * disposition:?string,resolution_id:?string}
     */
    public function delivery(EntityId $id): array;
    /** Protected decision metadata; never return the encrypted receipt as a browser payload.
     * @return array{resolution_id:string,encrypted_resolution:string}|null
     */
    public function deliveryDecision(EntityId $id): ?array;
    public function recordDeliveryDecision(
        EntityId $id,
        EntityId $campaign,
        int $expectedVersion,
        string $expectedStatus,
        DeliveryReviewDecision $decision,
        EntityId $operation,
        #[\SensitiveParameter] string $encryptedReceipt
    ): void;
    /** Complete bounded delivery set with minimized current person labels; 501 probes the 500-recipient bound.
     * @return list<array<string,mixed>>
     */
    public function deliveries(EntityId $campaign): array;
    /** Indexed aggregate counts; no recipient profiles or message content.
     * @return array<string,int>
     */
    public function deliveryCounts(EntityId $campaign): array;
    public function transitionDelivery(EntityId $id, string $status, int $expectedVersion): void;
    public function allFinished(EntityId $campaign): bool;
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

    /** One canonical, enabled recipient. No email or private source facts are returned.
     * @return array<string,string|null>
     */
    public function recordAudience(RecordRecipientSource $source, EntityId $record): array;

    /** Current canonical person/account labels for a complete, distinct shortlist of at most 25 students.
     * @param list<EntityId> $students
     * @return list<array<string,string|null>>
     */
    public function studentAudience(array $students): array;
}
