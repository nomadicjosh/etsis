<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Dto\AuthorizedCampaignDelivery;
use Domain\Communications\Enum\DeliveryReviewDecision;
use Domain\Communications\Repository\CampaignDispatchRepository;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\ValueObject\LocalDateTime;
use DomainException;
use Throwable;

/** Explicit background authorization; the existing delivery rows remain the recipient queue. */
final readonly class CampaignBackgroundService
{
    public function __construct(
        private CampaignService $campaigns,
        private CampaignRepository $messages,
        private CampaignDispatchRepository $dispatches,
        private CampaignDeliveryExecutor $executor,
        private MessageCipher $cipher,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock
    ) {
    }

    /** @return array<string,mixed> */
    public function setup(EntityId $id): array
    {
        $actor = $this->recipientAuthority();
        $this->campaigns->deliveryState($id, $actor);
        $now = $this->clock->now();
        return ['timezone' => $now->getTimezone()->getName(),
            'suggested_local' => $now->modify('+1 hour')->format('Y-m-d\TH:i')];
    }

    /** @return array<string,mixed> */
    public function review(EntityId $id, ?string $sendAt = null, ?string $timezone = null): array
    {
        $actor = $this->recipientAuthority();
        $campaign = $this->campaigns->deliveryState($id, $actor);
        if ($campaign->status !== 'queued') {
            throw new DomainException('Resume a queued campaign before reviewing background delivery.');
        }
        if (($this->dispatches->get($id)['status'] ?? null) === 'active') {
            throw new DomainException('Cancel the current background authorization before reviewing a replacement.');
        }
        $notBefore = $this->scheduledTime($sendAt, $timezone);
        $rows = $this->rows($id);
        $scope = $this->scope($rows);
        if ($scope === []) {
            throw new DomainException('There are no queued recipients to authorize.');
        }
        $message = $this->campaigns->show($id);
        if (!$message['can_dispatch']) {
            throw new DomainException('Configure institutional mail delivery before confirming background delivery.');
        }
        $token = $this->cipher->seal(json_encode([
            'kind' => 'campaign-background', 'campaign_id' => $id->value, 'actor_id' => $actor->value,
            'operation_id' => EntityId::generate()->value, 'campaign_version' => $campaign->version,
            'dispatch_version' => (int) ($this->dispatches->get($id)['version'] ?? 0),
            'message_hash' => hash('sha256', $campaign->encryptedContent),
            'not_before_at' => $notBefore,
            'timezone' => $this->clock->now()->getTimezone()->getName(),
            'expires_at' => $this->clock->now()->modify('+20 minutes')->getTimestamp(), 'deliveries' => $scope,
        ], JSON_THROW_ON_ERROR));
        $this->recipientAuthority($actor);
        $this->campaigns->deliveryState($id, $actor);
        return ['review_token' => $token, 'subject' => $message['subject'], 'body' => $message['body'],
            'send_after' => $this->timing($notBefore),
            'recipients' => array_values(array_map(static fn(array $r): array => [
                'name' => trim(($r['given_name'] ?? '') . ' ' . ($r['family_name'] ?? ''))
                    ?: 'Account …' . substr($r['recipient_user_id'], -8),
                'institutional_id' => $r['institutional_id'],
            ], array_filter($rows, static fn(array $r): bool => $r['status'] === 'queued')))];
    }

    /** Confirmation never calls the transport. Repeating the same accepted review cannot restart a job.
     * @return array<string,mixed>
     */
    public function confirm(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->recipientAuthority();
        $scope = $this->open($token);
        if ($scope['campaign_id'] !== $id->value || $scope['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('Review this message under your own account.');
        }
        $this->transactions->run(function () use ($id, $actor, $scope, $token): void {
            $this->messages->lock($id);
            $campaign = $this->campaigns->deliveryState($id, $actor);
            $job = $this->dispatches->get($id);
            if ($job !== null && hash_equals($job['encrypted_scope'], $token)) {
                return;
            }
            $notBefore = $scope['not_before_at'] ?? null;
            if (
                $scope['expires_at'] <= $this->clock->now()->getTimestamp()
                || $campaign->status !== 'queued' || $campaign->version !== $scope['campaign_version']
                || ($job['status'] ?? null) === 'active'
                || (int) ($job['version'] ?? 0) !== $scope['dispatch_version']
                || !hash_equals($scope['message_hash'], hash('sha256', $campaign->encryptedContent))
                || $this->scope($this->rows($id)) !== $scope['deliveries']
                || ($notBefore !== null && (new DateTimeImmutable($notBefore) <= $this->clock->now()
                    || $scope['timezone'] !== $this->clock->now()->getTimezone()->getName()))
            ) {
                throw new ConcurrentModificationException('The review changed or expired. Review delivery again.');
            }
            $this->dispatches->save([
                'id' => $id->value, 'actor_id' => $actor->value, 'operation_id' => $scope['operation_id'],
                'version' => $scope['dispatch_version'] + 1, 'status' => 'active',
                'encrypted_scope' => $token, 'updated_at' => $this->timestamp(),
                'not_before_at' => $notBefore,
            ], $scope['dispatch_version']);
            $this->audit->record($actor, 'campaign.background_authorized', $id);
            $this->recipientAuthority($actor);
            $this->campaigns->deliveryState($id, $actor);
        });
        return $this->status($id);
    }

    /** @return array<string,mixed> */
    public function status(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $campaign = $this->campaigns->deliveryState($id, $actor);
        $job = $this->dispatches->get($id);
        $counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'needs_review' => 0, 'closed' => 0];
        foreach ($this->messages->deliveryCounts($id) as $status => $count) {
            if (!array_key_exists($status, $counts)) {
                throw new DomainException('The delivery queue needs institutional review.');
            }
            $counts[$status] = $count;
        }
        if (array_sum($counts) > 500) {
            throw new DomainException('This campaign exceeds the recipient limit.');
        }
        $this->campaigns->deliveryState($id, $actor);
        return ['status' => $campaign->status === 'completed' ? 'completed' : ($job['status'] ?? 'unconfirmed'),
            'version' => (int) ($job['version'] ?? 0),
            'campaign_status' => $campaign->status, 'total' => array_sum($counts), 'counts' => $counts,
            'send_after' => $this->timing($job['not_before_at'] ?? null),
            'waiting' => ($job['status'] ?? null) === 'active' && ($job['not_before_at'] ?? null) !== null
                && new DateTimeImmutable($job['not_before_at']) > $this->clock->now()];
    }

    public function cancel(EntityId $id, int $expectedVersion): void
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        $this->transactions->run(function () use ($id, $actor, $expectedVersion): void {
            $this->messages->lock($id);
            $this->campaigns->deliveryState($id, $actor);
            $job = $this->dispatches->get($id);
            if ($job === null || (int) $job['version'] !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload delivery progress before cancelling.');
            }
            if ($job['status'] === 'active') {
                $job['status'] = 'cancelled';
                $job['version'] = $expectedVersion + 1;
                $job['updated_at'] = $this->timestamp();
                $this->dispatches->save($job, $expectedVersion);
                $this->audit->record($actor, 'campaign.background_cancelled', $id);
            }
            $this->campaigns->deliveryState($id, $actor);
        });
    }

    /** @return array<string,mixed> */
    public function deliveryReviewState(EntityId $id, EntityId $delivery): array
    {
        $actor = $this->decisionActor();
        [, $row, $job] = $this->decisionState($id, $delivery, $actor);
        $receipt = $this->messages->deliveryDecision($delivery);
        $decision = $receipt === null ? null
        : json_decode($this->cipher->open($receipt['encrypted_resolution']), true, flags: JSON_THROW_ON_ERROR);
        $this->decisionActor($actor);
        $this->campaigns->deliveryState($id, $actor);
        return ['delivery_id' => $delivery->value, 'transport_status' => $row['status'],
            'disposition' => $row['disposition'], 'worker_active' => ($job['status'] ?? null) === 'active',
            'decision' => $decision === null ? null : array_intersect_key($decision, array_flip([
                'decision', 'reason', 'evidence', 'actor_id', 'decided_at', 'transport_status', 'delivery_version',
            ]))];
    }

    /** @return array<string,mixed> */
    public function reviewDeliveryDecision(
        EntityId $id,
        EntityId $delivery,
        DeliveryReviewDecision $decision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?string $evidence = null
    ): array {
        $actor = $this->decisionActor();
        [$campaign, $row, $job] = $this->decisionState($id, $delivery, $actor);
        if (($job['status'] ?? null) === 'active') {
            throw new DomainException('Cancel background delivery before recording a delivery decision.');
        }
        if ($row['resolution_id'] !== null || !$decision->allows($row['status'])) {
            throw new DomainException('Review an unresolved delivery. A live send cannot be confirmed by staff.');
        }
        $reason = trim($reason);
        $evidence = $evidence === null ? null : trim($evidence);
        if ($reason === '' || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            throw new ValidationException([['field' => 'reason',
                'message' => 'Record a reason of 1000 characters or fewer.']]);
        }
        if (
            ($decision === DeliveryReviewDecision::ConfirmSent && ($evidence === null || $evidence === ''))
            || ($evidence !== null && (mb_strlen($evidence) > 191 || preg_match('/[\x00-\x1F\x7F]/', $evidence)))
        ) {
            throw new ValidationException([['field' => 'evidence',
                'message' => 'Record a mail-service receipt or institutional case reference for this confirmation.']]);
        }
        $data = ['kind' => 'campaign-delivery-decision', 'campaign_id' => $id->value,
            'delivery_id' => $delivery->value, 'actor_id' => $actor->value,
            'operation_id' => EntityId::generate()->value, 'campaign_version' => $campaign->version,
            'delivery_version' => $row['version'], 'transport_status' => $row['status'],
            'recipient_user_id' => $row['recipient_user_id'], 'job_version' => (int) ($job['version'] ?? 0),
            'job_operation' => $job['operation_id'] ?? null, 'decision' => $decision->value,
            'reason' => $reason, 'evidence' => $evidence,
            'expires_at' => $this->clock->now()->modify('+20 minutes')->getTimestamp()];
        $token = $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
        $this->decisionActor($actor);
        $this->campaigns->deliveryState($id, $actor);
        return ['review_token' => $token, 'decision' => $decision->value, 'reason' => $reason, 'evidence' => $evidence,
            'delivery_id' => $delivery->value, 'transport_status' => $row['status']];
    }

    /** This records a decision only. No transport, retry or queued authorization is invoked.
     * @return array<string,mixed>
     */
    public function confirmDeliveryDecision(
        EntityId $id,
        EntityId $delivery,
        #[\SensitiveParameter] string $token
    ): array {
        $actor = $this->decisionActor();
        try {
            $scope = strlen($token) <= 65536
            ? json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR) : null;
            if (!is_array($scope) || ($scope['kind'] ?? null) !== 'campaign-delivery-decision') {
                throw new DomainException('Invalid delivery review.');
            }
        } catch (Throwable) {
            throw new DomainException('This delivery review is unavailable. Review the current result again.');
        }
        if (
            $scope['campaign_id'] !== $id->value || $scope['delivery_id'] !== $delivery->value
            || $scope['actor_id'] !== $actor->value
        ) {
            throw new AccessDeniedException('Review this delivery under your own account.');
        }
        $this->transactions->run(function () use ($id, $delivery, $actor, $scope, $token): void {
            $this->messages->lock($id);
            $this->decisionActor($actor);
            [$campaign, $row, $job] = $this->decisionState($id, $delivery, $actor);
            $receipt = $this->messages->deliveryDecision($delivery);
            if ($receipt !== null) {
                $recorded = json_decode(
                    $this->cipher->open($receipt['encrypted_resolution']),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                if (
                    $receipt['resolution_id'] === $scope['operation_id']
                    && hash_equals($recorded['review_hash'], hash('sha256', $token))
                ) {
                    return;
                }
                throw new ConcurrentModificationException('This delivery already has a retained decision.');
            }
            $decision = DeliveryReviewDecision::from($scope['decision']);
            if (
                $scope['expires_at'] <= $this->clock->now()->getTimestamp()
                || $campaign->version !== $scope['campaign_version'] || $row['version'] !== $scope['delivery_version']
                || $row['status'] !== $scope['transport_status']
                || $row['recipient_user_id'] !== $scope['recipient_user_id']
                || !$decision->allows($row['status']) || ($job['status'] ?? null) === 'active'
                || (int) ($job['version'] ?? 0) !== $scope['job_version']
                || ($job['operation_id'] ?? null) !== $scope['job_operation']
            ) {
                throw new ConcurrentModificationException('The delivery changed or this review expired. Review again.');
            }
            $recorded = $scope + ['review_hash' => hash('sha256', $token), 'decided_at' => $this->timestamp()];
            $this->messages->recordDeliveryDecision(
                $delivery,
                $id,
                $row['version'],
                $row['status'],
                $decision,
                new EntityId($scope['operation_id']),
                $this->cipher->seal(json_encode($recorded, JSON_THROW_ON_ERROR))
            );
            if (in_array($campaign->status, ['queued', 'paused'], true) && $this->messages->allFinished($id)) {
                $this->messages->save($campaign->transition('completed'), $campaign->version);
            }
            $this->audit->record($actor, 'campaign.delivery_' . $decision->value, $delivery);
            $this->decisionActor($actor);
            $this->campaigns->deliveryState($id, $actor);
        });
        return $this->deliveryReviewState($id, $delivery);
    }

    private function decisionActor(?EntityId $expected = null): EntityId
    {
        $actor = $this->recipientAuthority($expected);
        if ($this->access->requirePermission('sis:communications:admin')->value !== $actor->value) {
            throw new AccessDeniedException('Communications administration access is required.');
        }
        return $actor;
    }

    /** @return array{\Domain\Communications\Campaign,array<string,mixed>,?array<string,mixed>} */
    private function decisionState(EntityId $id, EntityId $delivery, EntityId $actor): array
    {
        $campaign = $this->campaigns->deliveryState($id, $actor);
        $row = $this->messages->delivery($delivery);
        if ($row['campaign_id'] !== $id->value) {
            throw new AccessDeniedException('This delivery belongs to another campaign.');
        }
        return [$campaign, $row, $this->dispatches->get($id)];
    }

    /** No login-session dependency: the executor validates the accepted actor against current database authority.
     * @return array{examined:int,sent:int,held:int,blocked:int}
     */
    public function run(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new \InvalidArgumentException('Use a delivery limit from one through twenty.');
        }
        $counts = ['examined' => 0, 'sent' => 0, 'held' => 0, 'blocked' => 0];
        $deadline = microtime(true) + 30;
        foreach ($this->dispatches->pending($this->clock->now()) as $job) {
            if ($counts['examined'] >= $limit || microtime(true) >= $deadline) {
                break;
            }
            $id = new EntityId($job['id']);
            try {
                $scope = $this->open($job['encrypted_scope']);
                $campaign = $this->messages->get($id);
                if (
                    $scope['campaign_id'] !== $id->value || $scope['actor_id'] !== $job['actor_id']
                    || $scope['operation_id'] !== $job['operation_id']
                    || ($scope['not_before_at'] ?? null) !== $job['not_before_at']
                    || !hash_equals($scope['message_hash'], hash('sha256', $campaign->encryptedContent))
                ) {
                    throw new DomainException('Authorization does not match this message.');
                }
                $rows = array_column($this->rows($id), null, 'id');
                foreach ($scope['deliveries'] as $target) {
                    if ($counts['examined'] >= $limit || microtime(true) >= $deadline) {
                        break;
                    }
                    $delivery = $rows[$target[0]] ?? null;
                    if ($delivery === null || $delivery['recipient_user_id'] !== $target[1]) {
                        throw new DomainException('The reviewed recipient changed.');
                    }
                    if ($delivery['status'] === 'sent' || $delivery['disposition'] !== null) {
                        continue;
                    }
                    if ($delivery['status'] !== 'queued') {
                        $this->finish($job, 'held');
                        ++$counts['held'];
                        continue 2;
                    }
                    ++$counts['examined'];
                    $campaign = $this->messages->get($id);
                    if ($campaign->status === 'paused') {
                        continue 2;
                    }
                    $this->executor->execute(new AuthorizedCampaignDelivery(
                        $id,
                        new EntityId($job['actor_id']),
                        new EntityId($job['operation_id']),
                        (int) $job['version'],
                        new EntityId($target[0]),
                        $campaign->version,
                        $target[2]
                    ));
                    if ($this->messages->delivery(new EntityId($target[0]))['status'] === 'sent') {
                        ++$counts['sent'];
                    }
                }
                $fresh = array_column($this->rows($id), null, 'id');
                $allDone = array_all($scope['deliveries'], static fn(array $t): bool =>
                    isset($fresh[$t[0]]) && ($fresh[$t[0]]['status'] === 'sent'
                        || $fresh[$t[0]]['disposition'] !== null));
                $this->finish($job, $allDone ? 'completed' : 'active');
            } catch (Throwable) {
                // Never retry an unknown external effect. Known pre-claim failures keep the recipient queued.
                $uncertain = array_any($this->rows($id), static fn(array $r): bool =>
                    $r['disposition'] === null && in_array($r['status'], ['sending', 'needs_review'], true));
                $this->finish($job, $uncertain ? 'held' : 'blocked');
                ++$counts[$uncertain ? 'held' : 'blocked'];
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $job */
    private function finish(array $job, string $status): void
    {
        $id = new EntityId($job['id']);
        $this->transactions->run(function () use ($job, $id, $status): void {
            $this->messages->lock($id);
            $current = $this->dispatches->get($id);
            if (
                $current === null || $current['operation_id'] !== $job['operation_id']
                || (int) $current['version'] !== (int) $job['version'] || $current['status'] !== 'active'
            ) {
                return;
            }
            $current['status'] = $status;
            $current['updated_at'] = $this->timestamp();
            $current['version'] = (int) $job['version'] + 1;
            $this->dispatches->save($current, (int) $job['version']);
            if ($status !== 'active') {
                $this->audit->record(new EntityId($job['actor_id']), 'campaign.background_' . $status, $id);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    private function rows(EntityId $id): array
    {
        $rows = $this->messages->deliveries($id);
        if (count($rows) > 500) {
            throw new DomainException('This campaign exceeds the recipient limit.');
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array{string,string,int}>
     */
    private function scope(array $rows): array
    {
        return array_values(array_map(
            static fn(array $r): array =>
            [$r['id'], $r['recipient_user_id'], (int) $r['version']],
            array_filter($rows, static fn(array $r): bool => $r['status'] === 'queued' && $r['disposition'] === null)
        ));
    }

    /** @return array<string,mixed> */
    private function open(#[\SensitiveParameter] string $token): array
    {
        try {
            if (strlen($token) > 262144) {
                throw new DomainException('Review is too large.');
            }
            $scope = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($scope) || ($scope['kind'] ?? null) !== 'campaign-background'
                || !is_array($scope['deliveries'] ?? null) || $scope['deliveries'] === []
                || count($scope['deliveries']) > 500
            ) {
                throw new DomainException('Invalid review.');
            }
            return $scope;
        } catch (Throwable) {
            throw new DomainException('This delivery review is unavailable. Review the message again.');
        }
    }

    private function recipientAuthority(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:communications:manage');
        if (
            ($expected !== null && $actor->value !== $expected->value)
            || $this->access->requirePermission('sis:person:manage')->value !== $actor->value
        ) {
            throw new AccessDeniedException('Recipient review requires current person-directory access.');
        }
        return $actor;
    }

    private function scheduledTime(?string $local, ?string $timezone): ?string
    {
        if ($local === null) {
            return null;
        }
        $now = $this->clock->now();
        if ($timezone !== $now->getTimezone()->getName()) {
            throw new ValidationException([['field' => 'send_at',
                'message' => 'The institution time zone changed. Reopen delivery review and choose the time again.']]);
        }
        $instant = LocalDateTime::resolve($local, $now->getTimezone(), 'send_at');
        if ($instant <= $now) {
            throw new ValidationException([['field' => 'send_at', 'message' => 'Choose a future delivery time.']]);
        }
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array{utc:string,display:string,timezone:string}|null */
    private function timing(?string $utc): ?array
    {
        if ($utc === null) {
            return null;
        }
        $zone = $this->clock->now()->getTimezone();
        return ['utc' => $utc, 'display' => new DateTimeImmutable($utc)->setTimezone($zone)
            ->format('M j, Y \a\t g:i a T') . ' · ' . $zone->getName(), 'timezone' => $zone->getName()];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
