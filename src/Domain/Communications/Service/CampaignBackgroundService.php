<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Dto\AuthorizedCampaignDelivery;
use Domain\Communications\Repository\CampaignDispatchRepository;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
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
    public function review(EntityId $id): array
    {
        $actor = $this->recipientAuthority();
        $campaign = $this->campaigns->deliveryState($id, $actor);
        if ($campaign->status !== 'queued') {
            throw new DomainException('Resume a queued campaign before reviewing background delivery.');
        }
        if (($this->dispatches->get($id)['status'] ?? null) === 'active') {
            throw new DomainException('Cancel the current background authorization before reviewing a replacement.');
        }
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
            'expires_at' => $this->clock->now()->modify('+20 minutes')->getTimestamp(), 'deliveries' => $scope,
        ], JSON_THROW_ON_ERROR));
        $this->recipientAuthority($actor);
        $this->campaigns->deliveryState($id, $actor);
        return ['review_token' => $token, 'subject' => $message['subject'], 'body' => $message['body'],
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
            if (
                $scope['expires_at'] <= $this->clock->now()->getTimestamp()
                || $campaign->status !== 'queued' || $campaign->version !== $scope['campaign_version']
                || ($job['status'] ?? null) === 'active'
                || (int) ($job['version'] ?? 0) !== $scope['dispatch_version']
                || !hash_equals($scope['message_hash'], hash('sha256', $campaign->encryptedContent))
                || $this->scope($this->rows($id)) !== $scope['deliveries']
            ) {
                throw new ConcurrentModificationException('The review changed or expired. Review delivery again.');
            }
            $this->dispatches->save([
                'id' => $id->value, 'actor_id' => $actor->value, 'operation_id' => $scope['operation_id'],
                'version' => $scope['dispatch_version'] + 1, 'status' => 'active',
                'encrypted_scope' => $token, 'updated_at' => $this->timestamp(),
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
        $counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'needs_review' => 0];
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
        return ['status' => $job['status'] ?? 'unconfirmed', 'version' => (int) ($job['version'] ?? 0),
            'campaign_status' => $campaign->status, 'total' => array_sum($counts), 'counts' => $counts];
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
        foreach ($this->dispatches->pending() as $job) {
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
                    if ($delivery['status'] === 'sent') {
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
                $fresh = array_column($this->rows($id), 'status', 'id');
                $allDone = array_all($scope['deliveries'], static fn(array $t): bool =>
                    ($fresh[$t[0]] ?? null) === 'sent');
                $this->finish($job, $allDone ? 'completed' : 'active');
            } catch (Throwable) {
                // Never retry an unknown external effect. Known pre-claim failures keep the recipient queued.
                $uncertain = array_any($this->rows($id), static fn(array $r): bool =>
                    in_array($r['status'], ['sending', 'needs_review'], true));
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
            array_filter($rows, static fn(array $r): bool => $r['status'] === 'queued')
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

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
