<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Campaign;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class CampaignService
{
    public function __construct(
        private CampaignRepository $repository,
        private MessageCipher $cipher,
        private MailTransport $transport,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    public function create(#[\SensitiveParameter] string $subject, #[\SensitiveParameter] string $body): Campaign
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $campaign = new Campaign(
            EntityId::generate(),
            $actor,
            $this->encrypted($subject, $body)
        );
        $this->transactions->run(function () use ($campaign, $actor): void {
            $this->repository->add($campaign);
            $this->audit->record($actor, 'campaign.created', $campaign->id);
        });

        return $campaign;
    }

    private function encrypted(#[\SensitiveParameter] string $subject, #[\SensitiveParameter] string $body): string
    {
        if (
            trim($subject) === '' || mb_strlen($subject) > 191 || preg_match('/[\x00-\x1F\x7F]/', $subject)
            || trim($body) === '' || strlen($body) > 100000 || str_contains($body, "\0")
        ) {
            throw new InvalidArgumentException('A valid subject and bounded text message are required.');
        }
        return $this->cipher->seal(json_encode(['subject' => $subject, 'body' => $body], JSON_THROW_ON_ERROR));
    }

    public function edit(
        EntityId $id,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $body,
        int $expectedVersion
    ): void {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $this->owned($id);
        $encrypted = $this->encrypted($subject, $body);
        $this->transactions->run(function () use ($id, $encrypted, $expectedVersion, $actor): void {
            $this->repository->lock($id);
            $campaign = $this->repository->get($id);
            if ($campaign->status !== 'draft') {
                throw new DomainException('Only a draft campaign can be edited.');
            }
            if ($campaign->version !== $expectedVersion) {
                throw new ConcurrentModificationException('The campaign changed. Reload before editing.');
            }
            $this->repository->save(new Campaign(
                $id,
                $campaign->ownerUserId,
                $encrypted,
                'draft',
                $expectedVersion + 1
            ), $expectedVersion);
            $this->audit->record($actor, 'campaign.updated', $id);
        });
    }

    /**
     * @param list<EntityId> $recipients
     * @return list<EntityId>
     */
    public function queue(EntityId $id, array $recipients): array
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        if (count($recipients) < 1 || count($recipients) > 500) {
            throw new InvalidArgumentException('A bounded recipient list is required.');
        }
        $this->owned($id);

        return $this->transactions->run(function () use ($id, $recipients, $actor): array {
            $this->repository->lock($id);
            $campaign = $this->repository->get($id);
            if ($campaign->status !== 'draft') {
                throw new DomainException('Only a draft campaign can be queued.');
            }
            $deliveries = [];
            foreach ($recipients as $recipient) {
                $deliveries[] = $this->repository->enqueue($id, $recipient);
            }
            $this->repository->save($campaign->transition('queued'), $campaign->version);
            $this->audit->record($actor, 'campaign.queued', $id);

            return $deliveries;
        });
    }

    public function pause(EntityId $id, bool $pause): void
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $this->owned($id);
        $this->transactions->run(function () use ($id, $pause, $actor): void {
            $this->repository->lock($id);
            $campaign = $this->repository->get($id);
            $this->repository->save($campaign->transition($pause ? 'paused' : 'queued'), $campaign->version);
            $this->audit->record($actor, $pause ? 'campaign.paused' : 'campaign.resumed', $id);
        });
    }

    /**
     * The queue stores references only. An uncertain send remains held for reconciliation, never
     * automatically retried.
     */
    public function dispatch(EntityId $deliveryId): void
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $delivery = $this->repository->delivery($deliveryId);
        $campaignId = new EntityId($delivery['campaign_id']);
        $this->owned($campaignId);
        if ($delivery['status'] === 'queued') {
            $this->transport->assertReady();
        }
        $claimed = $this->transactions->run(function () use ($campaignId, $deliveryId): ?array {
            $this->repository->lock($campaignId);
            $delivery = $this->repository->delivery($deliveryId);
            if ($delivery['status'] !== 'queued') {
                return null;
            }
            if ($this->repository->get($campaignId)->status !== 'queued') {
                throw new DomainException('The campaign is not queued.');
            }
            $this->repository->transitionDelivery($deliveryId, 'sending', $delivery['version']);

            return $delivery;
        });
        if ($claimed === null) {
            return;
        }
        try {
            $campaign = $this->repository->get($campaignId);
            $content = json_decode($this->cipher->open($campaign->encryptedContent), true, flags: JSON_THROW_ON_ERROR);
            $this->transport->send(
                $deliveryId,
                $this->repository->recipient(new EntityId($claimed['recipient_user_id'])),
                $content['subject'],
                $content['body']
            );
        } catch (Throwable) {
            $this->repository->transitionDelivery($deliveryId, 'needs_review', $claimed['version'] + 1);
            throw new DomainException('Delivery could not be confirmed and requires reconciliation.');
        }
        $this->transactions->run(function () use ($campaignId, $deliveryId, $claimed, $actor): void {
            $this->repository->lock($campaignId);
            $this->repository->transitionDelivery($deliveryId, 'sent', $claimed['version'] + 1);
            $this->audit->record($actor, 'message.sent', $deliveryId);
            $campaign = $this->repository->get($campaignId);
            if ($campaign->status === 'queued' && $this->repository->allSent($campaignId)) {
                $this->repository->save($campaign->transition('completed'), $campaign->version);
            }
        });
    }

    /** @return array{id: string, status: string, subject: string, body: string, version: int, can_dispatch: bool} */
    public function show(EntityId $id): array
    {
        $this->access->requirePermission('sis:communications:manage');
        $campaign = $this->owned($id);
        /** @var array{subject: string, body: string} $content */
        $content = json_decode($this->cipher->open($campaign->encryptedContent), true, flags: JSON_THROW_ON_ERROR);

        $ready = true;
        try {
            $this->transport->assertReady();
        } catch (DomainException) {
            $ready = false;
        }

        return ['id' => $id->value, 'status' => $campaign->status, 'can_dispatch' => $ready,
            'subject' => $content['subject'], 'body' => $content['body'], 'version' => $campaign->version];
    }

    private function owned(EntityId $id): Campaign
    {
        $campaign = $this->repository->get($id);
        $this->access->requireOwnerOrPermission($campaign->ownerUserId, 'sis:communications:admin');

        return $campaign;
    }
}
