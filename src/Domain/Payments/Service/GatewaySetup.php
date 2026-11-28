<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Payments\Enum\GatewaySetupIssue;
use Domain\Payments\Exception\GatewaySetupException;
use DomainException;
use InvalidArgumentException;

/** Reviewed operational configuration. Credentials are never returned in a browser review token. */
final readonly class GatewaySetup
{
    public const string MANAGE = 'sis:payments:configure';

    public function __construct(
        private GatewayConfiguration $configuration,
        private GatewayClient $client,
        private GatewayPolicy $policy,
        private LedgerService $ledger,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proof,
    ) {
    }

    private function actor(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::MANAGE);
        if (
            $this->access->requirePermission('sis:finance:ledger')->value !== $actor->value
            || !$this->roles->allows($actor->value, self::MANAGE)
            || !$this->roles->allows($actor->value, 'sis:finance:ledger')
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    public function directory(string $kind, ?EntityId $after = null): array
    {
        $actor = $this->actor();
        $records = $this->configuration->records;
        $rows = match ($kind) {
            'connections' => $records->connections($after),
            'routes' => $records->routes($after),
            default => throw new InvalidArgumentException(),
        };
        $items = [];
        foreach (array_slice($rows, 0, 20) as $r) {
            $items[] = $kind === 'connections'
            ? GatewayConfiguration::summary($this->configuration->connection($r))
            : $this->presentRoute($this->configuration->route($r));
        }
        $this->actor($actor);
        return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null,
            'enabled' => $this->policy->enabled(), 'test_allowed' => $this->policy->allowTest()];
    }

    public function show(EntityId $id): array
    {
        $actor = $this->actor();
        $row = $this->configuration->records->connection($id) ?? throw new RecordNotFoundException();
        $data = GatewayConfiguration::summary($this->configuration->connection($row));
        $this->actor($actor);
        return $data;
    }

    public function routing(string $purpose, string $currency): array
    {
        $actor = $this->actor();
        $id = GatewayConfiguration::routeId($purpose, $currency);
        $row = $this->configuration->records->route($id);
        $data = $row === null ? ['id' => $id->value, 'purpose' => $purpose, 'currency' => $currency,
            'version' => 0, 'connection_id' => null] : $this->configuration->route($row);
        $data = $this->presentRoute($data);
        $this->actor($actor);
        return $data;
    }

    private function presentRoute(array $data): array
    {
        if ($data['connection_id'] !== null) {
            $row = $this->configuration->records->connection(new EntityId($data['connection_id']));
            $data['selected_connection'] = $row === null ? null
            : GatewayConfiguration::summary($this->configuration->connection($row));
        }
        return $data;
    }

    public function assets(string $currency, ?EntityId $after = null): array
    {
        $actor = $this->actor();
        new Money(0, $currency);
        $rows = $this->configuration->records->assets($currency, $after);
        $this->actor($actor);
        return ['items' => array_map(static fn(array $row): array => array_intersect_key(
            $row,
            array_flip(['id', 'code', 'title', 'currency', 'kind'])
        ), array_slice($rows, 0, 20)),
            'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    public function review(string $kind, #[\SensitiveParameter] array $input, string $reason): array
    {
        $actor = $this->actor();
        $reason = trim($reason);
        if (
            mb_strlen($reason) < 8 || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $reason)
        ) {
            throw new InvalidArgumentException('Provide a reason between eight and one thousand characters.');
        }
        // Credential validation can contact Stripe; it never runs while a transaction is held.
        $candidate = match ($kind) {
            'connection' => $this->connectionCandidate($input),
            'route' => $this->routeCandidate($input),
            'asset' => $this->assetCandidate($input),
            'classification' => $this->classificationCandidate($input),
            default => throw new InvalidArgumentException('Invalid payment setup action.'),
        };
        $id = EntityId::generate();
        $payload = ['id' => $id->value, 'actor_id' => $actor->value, 'kind' => $kind,
            'candidate' => $candidate, 'reason' => $reason];
        $revision = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->transactions->run(function () use ($id, $actor, $payload, $revision): void {
            $this->roles->lock();
            $this->actor($actor);
            $this->sources($payload['kind'], $payload['candidate']);
            $this->configuration->records->prune($actor, $this->clock->now()->getTimestamp());
            $this->configuration->records->stage(['id' => $id->value, 'actor_id' => $actor->value,
                'revision' => $revision, 'expires_at' => $this->clock->now()->getTimestamp() + 1200,
                'sealed_data' => $this->configuration->seal($payload), 'sealed_result' => null]);
            $this->actor($actor);
        });
        $preview = $candidate['data'];
        if ($kind === 'connection') {
            $preview = GatewayConfiguration::summary($preview);
        } elseif ($kind === 'route') {
            $preview['selected_connection'] = $candidate['connection']['summary'] ?? null;
        }
        return ['review_id' => $id->value, 'kind' => $kind, 'preview' => $preview, 'reason' => $reason,
            'review_token' => $this->proof->issue('payment-setup', 'confirm', $id, $actor, $id, $revision)];
    }

    public function confirm(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->actor();
        return $this->transactions->run(function () use ($id, $token, $actor): array {
            $this->roles->lock();
            $this->actor($actor);
            $row = $this->configuration->records->review($id, true);
            if ($row['actor_id'] !== $actor->value) {
                throw new AccessDeniedException();
            }
            $accepted = $row['sealed_result'] !== null;
            $this->proof->validate($token, 'payment-setup', 'confirm', $id, $actor, $id, $row['revision'], $accepted);
            if ($accepted) {
                $receipt = $this->configuration->open($row['sealed_result']);
                if (
                    $receipt['id'] !== $id->value || $receipt['actor_id'] !== $actor->value
                    || $receipt['revision'] !== $row['revision']
                ) {
                    throw new DomainException('Invalid payment setup receipt.');
                }
                return $receipt['result'];
            }
            if ($row['sealed_data'] === null || (int) $row['expires_at'] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException('This review expired. Review payment setup again.');
            }
            $p = $this->configuration->open($row['sealed_data']);
            if (
                $p['id'] !== $id->value || $p['actor_id'] !== $actor->value
                || !hash_equals($row['revision'], hash('sha256', json_encode($p, JSON_THROW_ON_ERROR)))
            ) {
                throw new DomainException('Invalid payment setup review.');
            }
            $kind = $p['kind'];
            $c = $p['candidate'];
            $this->sources($kind, $c);
            $d = $c['data'];
            $target = new EntityId($d['id']);
            $records = $this->configuration->records;
            if ($kind === 'connection') {
                $records->saveConnection($target, $d['version'], $this->configuration->seal($d));
            } elseif ($kind === 'route') {
                $records->saveRoute(
                    $target,
                    $d['purpose'],
                    $d['currency'],
                    $d['version'],
                    $this->configuration->seal($d)
                );
            } elseif ($kind === 'asset') {
                $target = $this->ledger->addAccount($d['code'], $d['title'], $d['currency'], 'asset', $target);
            } else {
                $records->classify($target, $d['kind']);
            }
            $result = ['id' => $target->value, 'status' => 'saved', 'kind' => $kind];
            $this->audit->record($actor, 'payment_setup.' . $kind . '_saved', $target);
            $this->actor($actor);
            // Audit handlers and permission/source changes cannot leave a partially accepted setup.
            if ($kind === 'connection') {
                if ($this->configuration->connection($records->connection($target, true)) !== $d) {
                    throw new ConcurrentModificationException();
                }
                $this->sameAsset($c['asset']);
            } elseif ($kind === 'route') {
                if ($this->configuration->route($records->route($target, true)) !== $d) {
                    throw new ConcurrentModificationException();
                }
                $this->sameConnection($c['connection']);
            } else {
                $a = $records->asset($target, true);
                if (array_intersect_key($a, $d) != $d) {
                    throw new ConcurrentModificationException();
                }
            }
            if ($this->clock->now()->getTimestamp() >= (int) $row['expires_at']) {
                throw new ConcurrentModificationException('This payment setup review expired before acceptance.');
            }
            $records->accept($id, $this->configuration->seal(['id' => $id->value, 'actor_id' => $actor->value,
                'revision' => $row['revision'], 'result' => $result, 'reason' => $p['reason'],
                'preview' => $kind === 'connection' ? GatewayConfiguration::summary($d)
                    : $d + ($kind === 'route' ? ['selected_connection' => $c['connection']['summary'] ?? null] : [])]));
            return $result;
        });
    }

    private function connectionCandidate(#[\SensitiveParameter] array $i): array
    {
        $old = $i['id'] === null ? null : ($this->configuration->records->connection(new EntityId($i['id']))
            ?? throw new RecordNotFoundException());
        if (($old === null ? 0 : (int) $old['version']) !== $i['version']) {
            throw new ConcurrentModificationException();
        }
        $previous = $old === null ? null : $this->configuration->connection($old);
        $name = trim($i['name']);
        if (
            $name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || !is_bool($i['live']) || !is_bool($i['enabled'])
        ) {
            throw new InvalidArgumentException('Provide a connection name and mode.');
        }
        if (!$i['live'] && !$this->policy->allowTest()) {
            throw new GatewaySetupException(GatewaySetupIssue::TestMode);
        }
        $currency = $i['currency'];
        new Money(0, $currency);
        $secret = $i['secret'] === '' ? ($previous['secret'] ?? '') : $i['secret'];
        $webhook = $i['webhook'] === '' ? ($previous['webhook'] ?? '') : $i['webhook'];
        try {
            GatewayConfiguration::credentials($secret, $webhook, $i['live']);
        } catch (InvalidArgumentException) {
            throw new GatewaySetupException(GatewaySetupIssue::Credentials);
        }
        $merchant = $previous !== null && $secret === $previous['secret']
        ? $previous['merchant'] : $this->client->merchant($secret);
        if (
            $previous !== null && ($previous['merchant'] !== $merchant || $previous['live'] !== $i['live']
            || $previous['currency'] !== $currency)
        ) {
            throw new GatewaySetupException(GatewaySetupIssue::Identity);
        }
        try {
            $asset = $this->configuration->asset(new EntityId($i['asset_id']), $currency);
        } catch (DomainException) {
            throw new GatewaySetupException(GatewaySetupIssue::Asset);
        }
        $data = ['id' => $old['id'] ?? EntityId::generate()->value, 'version' => $i['version'] + 1,
            'name' => $name, 'provider' => 'stripe', 'merchant' => $merchant, 'live' => $i['live'],
            'currency' => $currency, 'enabled' => $i['enabled'], 'asset' => $asset,
            'secret' => $secret, 'webhook' => $webhook];
        return ['data' => $data, 'old' => $this->fingerprint($old), 'asset' => $asset];
    }

    private function routeCandidate(array $i): array
    {
        $id = GatewayConfiguration::routeId($i['purpose'], $i['currency']);
        $old = $this->configuration->records->route($id);
        if (($old === null ? 0 : (int) $old['version']) !== $i['version']) {
            throw new ConcurrentModificationException();
        }
        $connection = $i['connection_id'] === null ? null
        : ($this->configuration->records->connection(new EntityId($i['connection_id']))
                ?? throw new RecordNotFoundException());
        if ($connection !== null) {
            $d = $this->configuration->connection($connection);
            if (!$d['enabled'] || $d['currency'] !== $i['currency'] || (!$d['live'] && !$this->policy->allowTest())) {
                throw new DomainException('Choose an enabled connection in this currency and permitted mode.');
            }
        }
        return ['data' => ['id' => $id->value, 'purpose' => $i['purpose'], 'currency' => $i['currency'],
            'version' => $i['version'] + 1, 'connection_id' => $i['connection_id']],
            'old' => $this->fingerprint($old), 'connection' => $connection === null ? null
                : ['id' => $connection['id'], 'hash' => $this->fingerprint($connection),
                    'summary' => GatewayConfiguration::summary($d)]];
    }

    private function assetCandidate(array $i): array
    {
        new Money(0, $i['currency']);
        if (
            trim($i['code']) === '' || strlen($i['code']) > 80 || trim($i['title']) === ''
            || mb_strlen($i['title']) > 191 || preg_match('/[\x00-\x1f\x7f]/', $i['code'] . $i['title'])
        ) {
            throw new InvalidArgumentException('Provide an account code and title.');
        }
        return ['data' => ['id' => EntityId::generate()->value, 'code' => trim($i['code']),
            'title' => trim($i['title']), 'currency' => $i['currency'], 'kind' => 'asset']];
    }

    private function classificationCandidate(array $i): array
    {
        if (\Domain\Finance\Enum\LedgerAccountKind::tryFrom($i['kind']) === null) {
            throw new InvalidArgumentException('Invalid ledger account classification.');
        }
        $a = $this->configuration->records->asset(new EntityId($i['id']));
        if ($a['kind'] !== null) {
            throw new DomainException('A classified account cannot change its financial kind.');
        }
        return ['data' => ['id' => $i['id'], 'kind' => $i['kind'], 'code' => $a['code'], 'title' => $a['title'],
            'currency' => $a['currency']], 'old' => $this->fingerprint($a)];
    }

    private function sources(string $kind, #[\SensitiveParameter] array $c): void
    {
        $d = $c['data'];
        $id = new EntityId($d['id']);
        $r = $this->configuration->records;
        $row = match ($kind) {
            'connection' => $r->connection($id, true),
            'route' => $r->route($id, true),
            'classification' => $r->asset($id, true),
            'asset' => null,
            default => throw new InvalidArgumentException(),
        };
        if ($kind === 'asset' && $r->codeExists($d['code'])) {
            throw new ConcurrentModificationException('This account code already exists. Choose another code.');
        }
        if ($kind !== 'asset' && $this->fingerprint($row) !== $c['old']) {
            throw new ConcurrentModificationException('Payment setup changed. Review the current configuration.');
        }
        if ($kind === 'connection') {
            $this->sameAsset($c['asset']);
            if (!$d['live'] && !$this->policy->allowTest()) {
                throw new DomainException('This installation does not permit test payments.');
            }
        } elseif ($kind === 'route') {
            $this->sameConnection($c['connection']);
        }
    }

    private function sameAsset(array $a): void
    {
        if ($this->configuration->asset(new EntityId($a['id']), $a['currency'], true) !== $a) {
            throw new ConcurrentModificationException('The selected clearing account changed.');
        }
    }

    private function sameConnection(?array $c): void
    {
        if (
            $c !== null && $this->fingerprint($this->configuration->records->connection(new EntityId($c['id']), true))
            !== $c['hash']
        ) {
            throw new ConcurrentModificationException('The selected payment connection changed.');
        }
    }

    private function fingerprint(#[\SensitiveParameter] ?array $row): ?string
    {
        return $row === null ? null : hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
