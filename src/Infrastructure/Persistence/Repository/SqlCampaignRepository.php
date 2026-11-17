<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Communications\Campaign;
use Domain\Communications\Enum\RecordRecipientSource;
use Domain\Communications\Enum\DeliveryReviewDecision;
use Domain\Communications\Enum\TemplateLibraryScope;
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

    public function templates(EntityId $actor, TemplateLibraryScope $scope, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $query = 'SELECT ' . implode(', ', array_map($q, ['id', 'owner_user_id', 'encrypted_content', 'version']))
        . ' FROM ' . $q('sis_campaigns') . ' WHERE ' . $q('status') . ' = ?';
        $status = $scope->status();
        $params = [$status];
        if ($scope->personal()) {
            $query .= ' AND ' . $q('owner_user_id') . ' = ?';
            $params[] = $actor->value;
        }
        if ($after !== null) {
            $query .= ' AND ' . $q('id') . ' > ?';
            $params[] = $after->value;
        }
        return array_map(static fn(array $row): Campaign => new Campaign(
            new EntityId($row['id']),
            new EntityId($row['owner_user_id']),
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
        $q = $this->sql->quote(...);
        $r = $this->sql->fetch('SELECT ' . implode(', ', array_map($q, [
            'campaign_id', 'recipient_user_id', 'status', 'version', 'disposition', 'resolution_id',
        ])) . ' FROM ' . $q('sis_deliveries') . ' WHERE ' . $q('id') . ' = ?', [$id->value], 1)[0]
        ?? throw new \Domain\Shared\Exception\RecordNotFoundException('Delivery not found.');

        return ['campaign_id' => $r['campaign_id'], 'recipient_user_id' => $r['recipient_user_id'],
            'status' => $r['status'], 'version' => (int) $r['version'],
            'disposition' => $r['disposition'], 'resolution_id' => $r['resolution_id']];
    }

    public function deliveryDecision(EntityId $id): ?array
    {
        $q = $this->sql->quote(...);
        $r = $this->sql->fetch('SELECT ' . $q('resolution_id') . ', ' . $q('encrypted_resolution')
            . ' FROM ' . $q('sis_deliveries') . ' WHERE ' . $q('id') . ' = ?', [$id->value], 1)[0] ?? null;
        if (($r['resolution_id'] ?? null) === null) {
            return null;
        }
        if (!is_string($r['resolution_id']) || !is_string($r['encrypted_resolution'])) {
            throw new DomainException('The retained delivery decision is unavailable.');
        }
        return ['resolution_id' => $r['resolution_id'], 'encrypted_resolution' => $r['encrypted_resolution']];
    }

    public function recordDeliveryDecision(
        EntityId $id,
        EntityId $campaign,
        int $expectedVersion,
        string $expectedStatus,
        DeliveryReviewDecision $decision,
        EntityId $operation,
        #[\SensitiveParameter] string $encryptedReceipt
    ): void {
        // Keep transport status/version independent so an already claimed send can record its real receipt.
        $q = $this->sql->quote(...);
        if (
            $this->sql->execute('UPDATE ' . $q('sis_deliveries') . ' SET ' . $q('disposition') . ' = ?, '
            . $q('resolution_id') . ' = ?, ' . $q('encrypted_resolution') . ' = ? WHERE ' . $q('id')
            . ' = ? AND ' . $q('campaign_id') . ' = ? AND ' . $q('version') . ' = ? AND ' . $q('status')
            . ' = ? AND ' . $q('resolution_id') . ' IS NULL AND ' . $q('disposition') . ' IS NULL', [
                $decision->value, $operation->value, $encryptedReceipt, $id->value, $campaign->value,
                $expectedVersion, $expectedStatus,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException('The delivery changed. Review its current result again.');
        }
    }

    public function deliveries(EntityId $campaign): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT d.' . $q('id') . ', d.' . $q('recipient_user_id') . ', d.' . $q('version') . ', d.' . $q('status')
            . ', d.' . $q('disposition')
            . ', p.' . $q('given_name') . ', p.' . $q('family_name') . ', i.' . $q('institutional_id')
            . ' FROM ' . $q('sis_deliveries') . ' d LEFT JOIN ' . $q('sis_persons') . ' p ON p.'
            . $q('owner_user_id') . ' = d.' . $q('recipient_user_id') . ' LEFT JOIN '
            . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = p.' . $q('id')
            . ' WHERE d.' . $q('campaign_id') . ' = ? ORDER BY d.' . $q('id'),
            [$campaign->value],
            501
        );
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

    public function deliveryCounts(EntityId $campaign): array
    {
        $q = $this->sql->quote(...);
        $effective = 'CASE WHEN ' . $q('status') . " = 'sent' THEN 'sent' WHEN " . $q('disposition')
        . " = 'confirmed_sent' THEN 'sent' WHEN " . $q('disposition') . " = 'closed' THEN 'closed' ELSE "
        . $q('status') . ' END';
        $rows = $this->sql->fetch('SELECT ' . $effective . ' AS ' . $q('status')
            . ', COUNT(*) AS ' . $q('delivery_count')
            . ' FROM ' . $q('sis_deliveries') . ' WHERE ' . $q('campaign_id') . ' = ? GROUP BY '
            . $effective, [$campaign->value], 6);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['delivery_count'];
        }
        return $counts;
    }

    public function allFinished(EntityId $campaign): bool
    {
        $counts = $this->deliveryCounts($campaign);
        return $counts !== [] && array_all(array_keys($counts), static fn(string $s): bool =>
            in_array($s, ['sent', 'closed'], true));
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
        return $this->validatedAudience($rows, count($users));
    }

    public function recordAudience(RecordRecipientSource $source, EntityId $record): array
    {
        $q = $this->sql->quote(...);
        $match = match ($source) {
            RecordRecipientSource::Person => '?',
            RecordRecipientSource::Student => '(SELECT s.' . $q('person_id') . ' FROM '
            . $q('sis_students') . ' s WHERE s.' . $q('id') . ' = ?)',
            RecordRecipientSource::Application => '(SELECT a.' . $q('person_id') . ' FROM '
            . $q('sis_applications') . ' a WHERE a.' . $q('id') . ' = ?)',
        };
        $rows = $this->sql->fetch(
            $this->recipientSelect() . ' AND p.' . $q('id') . ' = ' . $match,
            [$record->value],
            2
        );
        return $this->validatedAudience($rows, 1)[0];
    }

    public function studentAudience(array $students): array
    {
        if ($students === [] || count($students) > 25) {
            throw new DomainException('Choose between one and 25 students.');
        }
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            $this->recipientSelect() . ' AND p.' . $q('id') . ' IN (SELECT s.' . $q('person_id')
            . ' FROM ' . $q('sis_students') . ' s WHERE s.' . $q('id') . ' IN ('
            . implode(', ', array_fill(0, count($students), '?')) . ')) ORDER BY p.' . $q('id'),
            array_map(static fn (EntityId $student): string => $student->value, $students),
            26
        );
        return $this->validatedAudience($rows, count($students));
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array<string,string|null>>
     */
    private function validatedAudience(#[\SensitiveParameter] array $rows, int $expected): array
    {
        if (count($rows) !== $expected) {
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
