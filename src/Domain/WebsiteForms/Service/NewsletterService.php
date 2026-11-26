<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\NewsletterRepository;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;
use Throwable;

use function Codefy\Framework\Helpers\trans_html;

final readonly class NewsletterService
{
    public const string MANAGE = 'sis:website:forms:integrations';

    public function __construct(
        private NewsletterRepository $records,
        private NewsletterPolicy $policy,
        private MessageCipher $cipher,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function administrator(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::MANAGE);
        if (
            !$this->roles->allows($actor->value, self::MANAGE)
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    public function canManage(): bool
    {
        try {
            $this->administrator();
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    public function directory(?EntityId $after = null, bool $choices = false): array
    {
        $actor = $choices ? $this->access->requirePermission('vihzhuo:manage') : $this->administrator();
        $rows = $this->records->connections($after);
        $items = array_slice($rows, 0, 20);
        if ($choices) {
            $items = array_values(array_filter($items, static fn(array $row): bool => (int) $row['enabled'] === 1));
        }
        foreach ($items as &$item) {
            $item['enabled'] = (bool) $item['enabled'];
            $item['version'] = (int) $item['version'];
        }
        unset($item);
        if ($choices) {
            if ($this->access->requirePermission('vihzhuo:manage')->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        } else {
            $this->administrator($actor);
        }
        return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null,
            'delivery_enabled' => $this->policy->enabled()];
    }

    public function show(EntityId $id): array
    {
        $actor = $this->administrator();
        $row = $this->records->connection($id) ?? throw new RecordNotFoundException();
        $connection = $this->open($row);
        $this->administrator($actor);
        unset($row['sealed_data']);
        return $row + ['host' => $connection->host, 'list' => $connection->list, 'credentials_set' => true];
    }

    public function save(
        ?EntityId $id,
        string $name,
        bool $enabled,
        #[\SensitiveParameter] array $settings,
        ?int $version
    ): array {
        $actor = $this->administrator();
        if (
            trim($name) === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || (($id === null) !== ($version === null))
        ) {
            throw new \InvalidArgumentException();
        }
        return $this->transactions->run(function () use ($id, $name, $enabled, $settings, $version, $actor): array {
            $this->roles->lock();
            if ($id !== null) {
                $this->records->lockConnection($id);
            }
            $old = $id === null ? null : ($this->records->connection($id) ?? throw new RecordNotFoundException());
            if ($old !== null && (int) $old['version'] !== $version) {
                throw new ConcurrentModificationException();
            }
            $previous = $old === null ? null : $this->open($old);
            $id ??= EntityId::generate();
            $connection = new NewsletterConnection(
                $id,
                $settings['provider'] ?? $previous?->provider ?? '',
                $settings['url'] ?? $previous?->url ?? '',
                $settings['list'] ?? $previous?->list ?? '',
                ($settings['secret'] ?? '') === '' ? $previous?->secret ?? '' : $settings['secret'],
                ($settings['username'] ?? '') === '' ? $previous?->username ?? '' : $settings['username']
            );
            if (
                ($previous !== null && $previous->targetHash !== $connection->targetHash)
                || !$this->policy->permitsHost($connection->host)
            ) {
                throw new ValidationException([['field' => 'connection', 'message' => trans_html(
                    'Allow this host in server configuration. Create a new connection to change its '
                        . 'provider or destination.'
                )]]);
            }
            if ($connection->provider === 'webhook' && ($settings['idempotent_receiver'] ?? false) !== true) {
                throw new ValidationException([['field' => 'connection', 'message' => trans_html(
                    'Confirm that this webhook receiver deduplicates requests using Idempotency-Key.'
                )]]);
            }
            $payload = ['purpose' => 'website.newsletter.connection', 'id' => $id->value,
                'provider' => $connection->provider, 'url' => $connection->url, 'list' => $connection->list,
                'secret' => $connection->secret, 'username' => $connection->username];
            $row = ['id' => $id->value, 'name' => trim($name), 'provider' => $connection->provider,
                'enabled' => (int) $enabled, 'version' => ($version ?? -1) + 1,
                    'target_hash' => $connection->targetHash,
                'sealed_data' => $this->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR))];
            $this->records->saveConnection($row, $version);
            $this->audit->record($actor, 'website.newsletter_connection_saved', $id);
            $this->administrator($actor);
            if ($this->records->connection($id) != $row) {
                throw new ConcurrentModificationException();
            }
            unset($row['sealed_data']);
            return $row;
        });
    }

    public function open(#[\SensitiveParameter] array $row): NewsletterConnection
    {
        try {
            if (strlen($row['sealed_data']) > 32768) {
                throw new \RuntimeException();
            }
            $payload = json_decode($this->cipher->open($row['sealed_data']), true, 8, JSON_THROW_ON_ERROR);
            if (
                $payload['purpose'] !== 'website.newsletter.connection' || $payload['id'] !== $row['id']
                || $payload['provider'] !== $row['provider']
            ) {
                throw new \RuntimeException();
            }
            $connection = new NewsletterConnection(
                new EntityId($payload['id']),
                $payload['provider'],
                $payload['url'],
                $payload['list'],
                $payload['secret'],
                $payload['username']
            );
            if (!hash_equals($row['target_hash'], $connection->targetHash)) {
                throw new \RuntimeException();
            }
            return $connection;
        } catch (Throwable) {
            throw new \RuntimeException('Newsletter connection could not be verified.');
        }
    }

    public function validateBinding(?array $binding): void
    {
        if ($binding === null) {
            return;
        }
        $row = $this->records->connection(new EntityId($binding['connection_id']))
        ?? throw new RecordNotFoundException();
        $connection = $this->open($row);
        if (
            !(bool) $row['enabled'] || !hash_equals($row['target_hash'], $binding['target_hash'])
            || !$this->policy->permitsHost($connection->host)
        ) {
            throw new ValidationException([['field' => 'newsletter', 'message' => trans_html(
                'Choose an enabled newsletter connection and review this form again.'
            )]]);
        }
    }

    public function enqueue(EntityId $id, array $definition, #[\SensitiveParameter] array $answers): void
    {
        $binding = $definition['newsletter'];
        if ($binding === null || ($answers[$binding['consent_field']] ?? false) !== true) {
            return;
        }
        $this->records->enqueue(
            $id,
            new EntityId($binding['connection_id']),
            $binding['target_hash'],
            $this->clock->now()->getTimestamp()
        );
    }

    public function status(EntityId $id): ?array
    {
        $job = $this->records->job($id);
        return $job === null ? null : ['state' => $job['state'], 'issue' => $job['issue'],
            'version' => (int) $job['version'], 'attempts' => (int) $job['attempts'],
            'delivery_enabled' => $this->policy->enabled()];
    }

    /** Called only inside the existing inquiry's current manage/scope transaction. */
    public function requeue(EntityId $id, int $version): void
    {
        $job = $this->records->job($id) ?? throw new RecordNotFoundException();
        if ((int) $job['version'] !== $version || !in_array($job['state'], ['review', 'blocked'], true)) {
            throw new ConcurrentModificationException();
        }
        $this->records->finish($id, $version, 'queued', 'none', $this->clock->now()->getTimestamp());
    }

    public function remove(EntityId $id): void
    {
        $this->records->remove($id);
    }
}
