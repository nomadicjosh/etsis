<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use Domain\Communications\Dto\AuthorizedCampaignDelivery;

interface CampaignDeliveryExecutor
{
    public function execute(AuthorizedCampaignDelivery $delivery): void;
}
