<?php

declare(strict_types=1);

namespace Domain\Communications\Dto;

use Domain\Shared\ValueObject\EntityId;

/** References from an accepted, protected authorization; no message or address in the job. */
final readonly class AuthorizedCampaignDelivery
{
    public function __construct(
        public EntityId $campaign,
        public EntityId $actor,
        public EntityId $operation,
        public int $authorizationVersion,
        public EntityId $delivery,
        public int $campaignVersion,
        public int $deliveryVersion,
    ) {
    }
}
