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

    public function templates(EntityId $owner, bool $retired, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $query = 'SELECT ' . implode(', ', array_map($q, ['id', 'encrypted_content', 'version']))
        . ' FROM ' . $q('sis_campaigns') . ' WHERE ' . $q('owner_user_id') . ' = ? AND '
        . $q('status') . ' = ?';
        $status = $retired ? 'template_retired' : 'template';
        $params = [$owner->value, $status];
        if ($after !== null) {
            $query .= ' AND ' . $q('id') . ' > ?';
            $params[] = $after->value;
        }
        return array_map(static fn(array $row): Campaign => new Campaign(
            new EntityId($row['id']),
            $owner,
            $row['encrypted_content'],
            $status,
            (int) $row['version']
        ), $this->sql->fetch($query . ' ORDER BY ' . $q('id'), $params, 21));
    }

    public function enqueue(EntityId $campaign, array $recipients): array
    {
        $deliveries = [];
        foreach ($recipients as $recipient) {
            $id = EntityId::generate();
            $this->sql->insert('sis_deliveries', ['id' => $id->value, 'campaign_id' => $campaign->value,
                'recipient_user_id' => $recipient->value, 'status' => 'queued', 'version' => 0]);
            $deliveries[] = $id;
        }
        return $deliveries;
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
        if (
            (int) $row['sign_in_enabled'] !== 1 || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new DomainException('The recipient account or delivery address is unavailable.');
        }

        return $email;
    }

    public function recipientChoices(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $query = $this->recipientSelect();
        $literal = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $parts = [];
        foreach (['p.given_name', 'p.family_name', 'i.institutional_id'] as $column) {
            [$alias, $field] = explode('.', $column);
            $parts[] = 'LOWER(' . $alias . '.' . $q($field) . ") LIKE ? ESCAPE '!'";
        }
        $query .= ' AND (' . implode(' OR ', $parts) . ')';
        $params = [$literal, $literal, $literal];
        if ($after !== null) {
            $query .= ' AND p.' . $q('id') . ' > ?';
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch($query . ' ORDER BY p.' . $q('id'), $params, 51);
        return array_map($this->recipientLabel(...), $rows);
    }

    public function audience(array $users): array
    {
        if ($users === [] || count($users) > 500) {
            throw new DomainException('Choose between one and 500 recipients.');
        }
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            $this->recipientSelect() . ' AND u.' . $q('user_id') . ' IN ('
                . implode(', ', array_fill(0, count($users), '?')) . ') ORDER BY p.' . $q('id'),
            array_map(static fn(EntityId $user): string => $user->value, $users),
            501
        );
        if (count($rows) !== count($users)) {
            throw new DomainException(
                'A selected person no longer has an available linked sign-in account. Review recipients again.'
            );
        }
        foreach ($rows as $row) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('A selected person needs a valid delivery address. Review recipients again.');
            }
        }
        return array_map($this->recipientLabel(...), $rows);
    }

    private function recipientSelect(): string
    {
        $q = $this->sql->quote(...);
        return 'SELECT p.' . $q('id') . ', p.' . $q('owner_user_id') . ' AS ' . $q('user_id')
        . ', p.' . $q('given_name') . ', p.' . $q('family_name') . ', i.' . $q('institutional_id')
        . ', u.' . $q('email') . ' FROM ' . $q('sis_persons') . ' p JOIN ' . $q('users')
        . ' u ON u.' . $q('user_id') . ' = p.' . $q('owner_user_id') . ' LEFT JOIN '
        . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = p.' . $q('id')
        . ' WHERE u.' . $q('sign_in_enabled') . ' = 1';
    }

    /** @param array<string,mixed> $row
     * @return array<string,string|null>
     */
    private function recipientLabel(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'id', 'user_id', 'given_name', 'family_name', 'institutional_id',
        ]));
    }
}
