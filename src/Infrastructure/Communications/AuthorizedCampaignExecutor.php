<?php

declare(strict_types=1);

namespace Infrastructure\Communications;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Dto\AuthorizedCampaignDelivery;
use Domain\Communications\Repository\CampaignDispatchRepository;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Service\CampaignDeliveryExecutor;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Persistence\UnitOfWork;

final readonly class AuthorizedCampaignExecutor implements CampaignDeliveryExecutor
{
    public function __construct(
        private CampaignRepository $messages,
        private CampaignDispatchRepository $jobs,
        private AccessRepository $permissions,
        private MessageCipher $cipher,
        private MailTransport $transport,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    public function execute(AuthorizedCampaignDelivery $delivery): void
    {
        $service = new CampaignService(
            $this->messages,
            $this->cipher,
            $this->transport,
            new CampaignWorkerAccess($delivery, $this->jobs, $this->permissions),
            $this->transactions,
            $this->audit
        );
        $service->dispatch(
            $delivery->delivery,
            $delivery->campaign,
            $delivery->campaignVersion,
            $delivery->deliveryVersion
        );
    }
}
