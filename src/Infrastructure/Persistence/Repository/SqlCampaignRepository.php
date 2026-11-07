<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Communications\Campaign;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCampaignRepository implements CampaignRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function add(Campaign $campaign): void
    {
        $this->sql->insert('sis_campaigns', ['id' => $campaign->id->value,
            'owner_user_id' => $campaign->ownerUserId->value,
            'encrypted_content' => $campaign->encryptedContent, 'status' => $campaign->status,
                'version' => $campaign->version]);
    }

    public function get(EntityId $id): Campaign
    {
        $r = $this->sql->one('sis_campaigns', ['id' => $id->value]);

        return new Campaign(
            $id,
            new EntityId($r['owner_user_id']),
            $r['encrypted_content'],
            $r['status'],
            (int) $r['version']
        );
    }

    public function save(Campaign $campaign, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_campaigns',
                ['status' => $campaign->status, 'version' => $campaign->version,
                    'encrypted_content' => $campaign->encryptedContent],
                ['id' => $campaign->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The campaign changed.');
        }
    }

    public function lock(EntityId $campaign): void
    {
        $this->sql->lock('sis_campaigns', $campaign->value, 'version');
    }

    public function enqueue(EntityId $campaign, EntityId $recipient): EntityId
    {
        $this->recipient($recipient);
        $criteria = ['campaign_id' => $campaign->value, 'recipient_user_id' => $recipient->value];
        $existing = $this->sql->rows('sis_deliveries', $criteria);
        if ($existing !== []) {
            return new EntityId($existing[0]['id']);
        }
        $id = EntityId::generate();
        $this->sql->insert('sis_deliveries', ['id' => $id->value] + $criteria + ['status' => 'queued', 'version' => 0]);

        return $id;
    }

    public function delivery(EntityId $id): array
    {
        $r = $this->sql->one('sis_deliveries', ['id' => $id->value]);

        return ['campaign_id' => $r['campaign_id'], 'recipient_user_id' => $r['recipient_user_id'],
            'status' => $r['status'], 'version' => (int) $r['version']];
    }

    public function transitionDelivery(EntityId $id, string $status, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_deliveries',
                ['status' => $status, 'version' => $expectedVersion + 1],
                ['id' => $id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The delivery changed.');
        }
    }

    public function allSent(EntityId $campaign): bool
    {
        $rows = $this->sql->rows('sis_deliveries', ['campaign_id' => $campaign->value]);

        return $rows !== [] && array_all($rows, static fn (array $r): bool => $r['status'] === 'sent');
    }

    public function recipient(EntityId $id): string
    {
        $row = $this->sql->one('users', ['user_id' => $id->value]);
        $email = $row['email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('The recipient has no valid delivery address.');
        }

        return $email;
    }
}
