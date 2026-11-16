<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Campaign;
use Domain\Communications\Enum\TemplateLibraryScope;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Shared\Exception\AccessDeniedException;
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
        return $this->createMessage($subject, $body, 'draft');
    }

    public function createTemplate(
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $body
    ): Campaign {
        return $this->createMessage($subject, $body, 'template');
    }

    private function createMessage(
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $body,
        string $status
    ): Campaign {
        $actor = $this->authorize();
        $campaign = new Campaign(
            EntityId::generate(),
            $actor,
            $this->encrypted($subject, $body),
            $status
        );
        $this->transactions->run(function () use ($campaign, $actor): void {
            $this->repository->add($campaign);
            $this->audit->record($actor, $campaign->status === 'template'
                ? 'campaign.template_created' : 'campaign.created', $campaign->id);
            $this->owned($campaign->id, $actor);
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
        $actor = $this->authorize();
        $this->owned($id, $actor);
        $encrypted = $this->encrypted($subject, $body);
        $this->transactions->run(function () use ($id, $encrypted, $expectedVersion, $actor): void {
            $this->repository->lock($id);
            $campaign = $this->owned($id, $actor);
            if (!in_array($campaign->status, ['draft', 'template'], true)) {
                throw new DomainException('Only a draft or active reusable template can be edited.');
            }
            if ($campaign->version !== $expectedVersion) {
                throw new ConcurrentModificationException('The campaign changed. Reload before editing.');
            }
            $this->repository->save(new Campaign(
                $id,
                $campaign->ownerUserId,
                $encrypted,
                $campaign->status,
                $expectedVersion + 1
            ), $expectedVersion);
            $this->audit->record($actor, $campaign->status === 'template'
                ? 'campaign.template_updated' : 'campaign.updated', $id);
            $this->owned($id, $actor);
        });
    }

    /** Template subjects are decrypted for the bounded, authorized page; bodies stay out of list responses.
     * @return array{items:list<array{id:string,subject:string,version:int}>,next_after:?string}
     */
    public function templates(TemplateLibraryScope $scope = TemplateLibraryScope::Mine, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        if ($scope === TemplateLibraryScope::Review) {
            $this->authorizeTemplateReview($actor);
        }
        $rows = $this->repository->templates($actor, $scope, $after);
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            if (
                ($scope->personal() && $row->ownerUserId->value !== $actor->value)
                || $row->status !== $scope->status()
            ) {
                throw new AccessDeniedException('Access denied.');
            }
            $content = json_decode($this->cipher->open($row->encryptedContent), true, flags: JSON_THROW_ON_ERROR);
            $items[] = ['id' => $row->id->value, 'subject' => $content['subject'], 'version' => $row->version];
        }
        $this->authorize($actor);
        if ($scope === TemplateLibraryScope::Review) {
            $this->authorizeTemplateReview($actor);
        }
        return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]->id->value : null];
    }

    public function draftFromTemplate(EntityId $id, int $expectedVersion): Campaign
    {
        $actor = $this->authorize();
        $this->owned($id, $actor, allowShared: true);
        return $this->transactions->run(function () use ($id, $expectedVersion, $actor): Campaign {
            $this->repository->lock($id);
            $template = $this->owned($id, $actor, allowShared: true);
            $this->templateVersion($template, $expectedVersion);
            if (!in_array($template->status, ['template', 'template_shared'], true)) {
                throw new DomainException('Use an active private or approved institutional template.');
            }
            // Copy encrypted composition only. No recipients, deliveries or later source edits follow it.
            $draft = new Campaign(EntityId::generate(), $actor, $template->encryptedContent);
            $this->repository->add($draft);
            $this->audit->record($actor, 'campaign.created_from_template', $draft->id);
            $this->owned($id, $actor, allowShared: true);
            $this->owned($draft->id, $actor);
            return $draft;
        });
    }

    public function retireTemplate(EntityId $id, int $expectedVersion, bool $retired): void
    {
        $actor = $this->authorize();
        $this->owned($id, $actor);
        $this->transactions->run(function () use ($id, $expectedVersion, $retired, $actor): void {
            $this->repository->lock($id);
            $template = $this->owned($id, $actor);
            $this->templateVersion($template, $expectedVersion);
            if (!$retired && in_array($template->status, ['template_review', 'template_shared'], true)) {
                throw new DomainException('Withdraw the template before returning to private editing.');
            }
            $next = $retired ? 'template_retired' : 'template';
            if ($template->status !== $next) {
                $this->repository->save($template->transition($next), $expectedVersion);
                $this->audit->record(
                    $actor,
                    $retired ? 'campaign.template_retired' : 'campaign.template_restored',
                    $id
                );
            }
            $this->owned($id, $actor);
        });
    }

    private function templateVersion(Campaign $template, int $expectedVersion): void
    {
        if (
            !in_array($template->status, Campaign::TEMPLATE_STATES, true)
            || $template->version !== $expectedVersion
        ) {
            throw new ConcurrentModificationException('The template changed. Reopen it before continuing.');
        }
    }

    public function reviewTemplate(EntityId $id, int $expectedVersion, string $action): void
    {
        $reviewer = in_array($action, ['approve', 'return'], true);
        $actor = $this->authorize();
        if ($reviewer) {
            $this->authorizeTemplateReview($actor);
        }
        $this->owned($id, $actor);
        $this->transactions->run(function () use ($id, $expectedVersion, $action, $reviewer, $actor): void {
            $this->repository->lock($id);
            if ($reviewer) {
                $this->authorizeTemplateReview($actor);
            }
            $template = $this->owned($id, $actor);
            $this->templateVersion($template, $expectedVersion);
            $expected = match ($action) {
                'submit' => ['template'],
                'withdraw' => ['template_review', 'template_shared'],
                'approve', 'return' => ['template_review'],
                default => throw new InvalidArgumentException('Choose a template review action.'),
            };
            if (!in_array($template->status, $expected, true)) {
                throw new ConcurrentModificationException('Reopen the template and review its current status.');
            }
            $next = match ($action) {
                'submit' => 'template_review',
                'approve' => 'template_shared',
                default => 'template',
            };
            $this->repository->save($template->transition($next), $expectedVersion);
            $this->audit->record($actor, 'campaign.template_' . $action, $id);
            $this->owned($id, $actor);
            if ($reviewer) {
                $this->authorizeTemplateReview($actor);
            }
        });
    }

    /**
     * @param list<EntityId> $recipients
     * @return list<EntityId>
     */
    public function queue(EntityId $id, array $recipients, int $expectedVersion): array
    {
        $actor = $this->authorizeRecipients();
        $recipients = $this->uniqueRecipients($recipients);
        $this->owned($id, $actor);

        return $this->transactions->run(function () use ($id, $recipients, $actor, $expectedVersion): array {
            $this->repository->lock($id);
            $campaign = $this->owned($id, $actor);
            $this->reviewable($campaign, $expectedVersion);
            $this->repository->audience($recipients);
            $deliveries = $this->repository->enqueue($id, $recipients);
            $this->repository->save($campaign->transition('queued'), $campaign->version);
            $this->audit->record($actor, 'campaign.queued', $id);
            $this->owned($id, $actor);
            $this->authorizeRecipients($actor);

            return $deliveries;
        });
    }

    /** @return array{items:list<array<string,string|null>>,next_after:?string} */
    public function recipientChoices(string $search, ?EntityId $after = null): array
    {
        $actor = $this->authorizeRecipients();
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new InvalidArgumentException('Search by name or institutional ID using up to 80 characters.');
        }
        $rows = trim($search) === '' ? [] : $this->repository->recipientChoices(trim($search), $after);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $this->authorizeRecipients($actor);
        return ['items' => $rows, 'next_after' => $more ? $rows[49]['id'] : null];
    }

    /** Read-only review of the exact draft revision and complete selected audience.
     * @param list<EntityId> $recipients
     * @return array<string,mixed>
     */
    public function reviewRecipients(EntityId $id, array $recipients, int $expectedVersion): array
    {
        $actor = $this->authorizeRecipients();
        $campaign = $this->owned($id, $actor);
        $this->reviewable($campaign, $expectedVersion);
        $people = $this->repository->audience($this->uniqueRecipients($recipients));
        $content = json_decode($this->cipher->open($campaign->encryptedContent), true, flags: JSON_THROW_ON_ERROR);
        $this->reviewable($this->owned($id, $actor), $expectedVersion);
        $this->authorizeRecipients($actor);
        return ['subject' => $content['subject'], 'body' => $content['body'],
            'version' => $campaign->version, 'recipients' => $people];
    }

    private function reviewable(Campaign $campaign, int $expectedVersion): void
    {
        if ($campaign->status !== 'draft' || $campaign->version !== $expectedVersion) {
            throw new ConcurrentModificationException(
                'The message changed. Reopen it and review the current draft and recipients.'
            );
        }
    }

    /** @param list<EntityId> $recipients
     * @return list<EntityId>
     */
    private function uniqueRecipients(array $recipients): array
    {
        if (count($recipients) < 1 || count($recipients) > 500) {
            throw new InvalidArgumentException('Choose between one and 500 recipients.');
        }
        $unique = [];
        foreach ($recipients as $recipient) {
            $unique[$recipient->value] = $recipient;
        }
        return array_values($unique);
    }

    public function pause(EntityId $id, bool $pause): void
    {
        $actor = $this->authorize();
        $this->owned($id, $actor);
        $this->transactions->run(function () use ($id, $pause, $actor): void {
            $this->repository->lock($id);
            $campaign = $this->owned($id, $actor);
            $this->repository->save($campaign->transition($pause ? 'paused' : 'queued'), $campaign->version);
            $this->audit->record($actor, $pause ? 'campaign.paused' : 'campaign.resumed', $id);
            $this->owned($id, $actor);
        });
    }

    /** Protected queue state for connected workflows; never exposed as a browser payload. */
    public function deliveryState(EntityId $id, EntityId $actor): Campaign
    {
        return $this->owned($id, $actor);
    }

    /** Claim only the specified parent and delivery revisions. Uncertain sends are never retried. */
    public function dispatch(
        EntityId $deliveryId,
        EntityId $expectedCampaign,
        int $campaignVersion,
        int $deliveryVersion
    ): void {
        $actor = $this->authorize();
        $delivery = $this->repository->delivery($deliveryId);
        $campaignId = new EntityId($delivery['campaign_id']);
        if ($expectedCampaign->value !== $campaignId->value) {
            throw new ConcurrentModificationException('The reviewed delivery belongs to another message.');
        }
        $this->owned($campaignId, $actor);
        if ($delivery['status'] === 'queued') {
            $this->transport->assertReady();
        }
        $claimed = $this->transactions->run(function () use (
            $campaignId,
            $deliveryId,
            $actor,
            $campaignVersion,
            $deliveryVersion
        ): ?array {
            $this->repository->lock($campaignId);
            $campaign = $this->owned($campaignId, $actor);
            $delivery = $this->repository->delivery($deliveryId);
            if ($delivery['status'] !== 'queued' || $delivery['disposition'] !== null) {
                return null;
            }
            if (
                $campaign->version !== $campaignVersion || $delivery['version'] !== $deliveryVersion
            ) {
                throw new ConcurrentModificationException('Review the message batch again before sending.');
            }
            if ($campaign->status !== 'queued') {
                throw new DomainException('The campaign is not queued.');
            }
            // Known recipient problems stop before a claim, without creating an uncertain-send state.
            $address = $this->repository->recipient(new EntityId($delivery['recipient_user_id']));
            $this->repository->transitionDelivery($deliveryId, 'sending', $delivery['version']);
            $this->owned($campaignId, $actor);

            return $delivery + ['address' => $address];
        });
        if ($claimed === null) {
            return;
        }
        try {
            $campaign = $this->repository->get($campaignId);
            $content = json_decode($this->cipher->open($campaign->encryptedContent), true, flags: JSON_THROW_ON_ERROR);
            $this->transport->send(
                $deliveryId,
                $claimed['address'],
                $content['subject'],
                $content['body']
            );
        } catch (Throwable) {
            $this->repository->transitionDelivery($deliveryId, 'needs_review', $claimed['version'] + 1);
            throw new DomainException('Delivery could not be confirmed and requires reconciliation.');
        }
        // Record an already attempted external effect even if access changes during transport.
        $this->transactions->run(function () use ($campaignId, $deliveryId, $claimed, $actor): void {
            $this->repository->lock($campaignId);
            $this->repository->transitionDelivery($deliveryId, 'sent', $claimed['version'] + 1);
            $this->audit->record($actor, 'message.sent', $deliveryId);
            $campaign = $this->repository->get($campaignId);
            if ($campaign->status === 'queued' && $this->repository->allFinished($campaignId)) {
                $this->repository->save($campaign->transition('completed'), $campaign->version);
            }
        });
    }

    /** @return array{id:string,status:string,subject:string,body:string,version:int,can_dispatch:bool,is_owner:bool} */
    public function show(EntityId $id): array
    {
        $actor = $this->authorize();
        $campaign = $this->owned($id, $actor, allowShared: true);
        /** @var array{subject: string, body: string} $content */
        $content = json_decode($this->cipher->open($campaign->encryptedContent), true, flags: JSON_THROW_ON_ERROR);

        $ready = !in_array($campaign->status, Campaign::TEMPLATE_STATES, true);
        if ($ready) {
            try {
                $this->transport->assertReady();
            } catch (DomainException) {
                $ready = false;
            }
        }
        $this->owned($id, $actor, allowShared: true);

        return ['id' => $id->value, 'status' => $campaign->status, 'can_dispatch' => $ready,
            'subject' => $content['subject'], 'body' => $content['body'], 'version' => $campaign->version,
            'is_owner' => $campaign->ownerUserId->value === $actor->value];
    }

    private function owned(EntityId $id, EntityId $actor, bool $allowShared = false): Campaign
    {
        $this->authorize($actor);
        $campaign = $this->repository->get($id);
        if (
            !($allowShared && $campaign->status === 'template_shared')
            && $this->access->requireOwnerOrPermission($campaign->ownerUserId, 'sis:communications:admin')->value
                !== $actor->value
        ) {
            throw new AccessDeniedException('Access denied.');
        }
        $this->authorize($actor);

        return $campaign;
    }

    private function authorizeTemplateReview(EntityId $actor): void
    {
        $this->authorize($actor);
        if ($this->access->requirePermission('sis:communications:admin')->value !== $actor->value) {
            throw new AccessDeniedException('Communications administration permission is required.');
        }
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('Access denied.');
        }
        return $actor;
    }

    private function authorizeRecipients(?EntityId $expected = null): EntityId
    {
        $actor = $this->authorize($expected);
        if ($this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
        return $actor;
    }
}
