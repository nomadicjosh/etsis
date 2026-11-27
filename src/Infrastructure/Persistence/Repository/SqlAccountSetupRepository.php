<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Person\Repository\AccountSetupRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAccountSetupRepository implements AccountSetupRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function stage(array $row): void
    {
        $this->sql->insert('sis_account_setup', $row);
    }
    public function byToken(string $hash): ?array
    {
        return $this->sql->rows('sis_account_setup', ['token_hash' => $hash], 1)[0] ?? null;
    }
    public function forPerson(EntityId $person): ?array
    {
        return $this->sql->rows('sis_account_setup', ['person_id' => $person->value], 1)[0] ?? null;
    }
    public function locked(EntityId $id): array
    {
        $this->sql->lock('sis_account_setup', $id->value, 'id');
        return $this->sql->one('sis_account_setup', ['id' => $id->value]);
    }
    public function consume(EntityId $id, string $at): void
    {
        $this->changeUnused($id, ['consumed_at' => $at, 'encrypted_notice' => null, 'state' => 'used']);
    }
    public function pending(int $limit): array
    {
        $q = $this->sql->quote(...);
        return array_map(static fn(array $r): EntityId => new EntityId($r['id']), $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_account_setup')
                . ' WHERE ' . $q('state') . ' = ? ORDER BY ' . $q('id'),
            ['queued'],
            $limit
        ));
    }
    public function claim(EntityId $id): bool
    {
        return $this->sql->update(
            'sis_account_setup',
            ['state' => 'sending'],
            ['id' => $id->value,
            'state' => 'queued']
        ) === 1;
    }
    public function delivery(EntityId $id, string $tokenHash, string $state): void
    {
        if (!in_array($state, ['sent', 'uncertain', 'expired'], true)) {
            throw new \InvalidArgumentException();
        }
        $this->sql->update(
            'sis_account_setup',
            ['state' => $state,
            'encrypted_notice' => null],
            ['id' => $id->value,
            'token_hash' => $tokenHash,
            'state' => 'sending']
        );
    }
    public function renew(EntityId $id, array $changes): void
    {
        $this->changeUnused($id, $changes);
    }

    public function retentionCandidates(string $cutoff, ?EntityId $after, int $limit): array
    {
        if ($limit < 1 || $limit > 101) {
            throw new \InvalidArgumentException('Use a bounded setup-retention page.');
        }
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_account_setup') .
            ' WHERE ' . $q('state') . ' IN (?, ?) AND ' . $q('encrypted_notice') . ' IS NOT NULL AND ' .
            $q('expires_at') . ' <= ?' . ($after === null ? '' : ' AND ' . $q('id') . ' > ?') .
            ' ORDER BY ' . $q('id'),
            ['queued', 'sending', $cutoff, ...($after === null ? [] : [$after->value])],
            $limit
        );
        return array_map(static fn(array $row): EntityId => new EntityId($row['id']), $rows);
    }

    public function retentionRecord(EntityId $id, bool $lock = false): ?array
    {
        if ($lock) {
            $this->sql->lock('sis_account_setup', $id->value, 'id');
        }
        $q = $this->sql->quote(...);
        $fields = array_map($q, ['id', 'person_id', 'user_id', 'token_hash', 'credential_hash',
            'expires_at', 'requested_at', 'consumed_at', 'state']);
        $substring = in_array($this->sql->driver(), ['sqlite', 'oci'], true) ? 'SUBSTR' : 'SUBSTRING';
        // The extra character lets verification reject oversized grants without loading an unbounded LOB.
        $fields[] = $substring . '(' . $q('sealed_grant') . ', 1, 8193) AS ' . $q('sealed_grant');
        $fields[] = 'CASE WHEN ' . $q('encrypted_notice') . ' IS NULL THEN 0 ELSE 1 END AS ' . $q('has_notice');
        $row = $this->sql->fetch('SELECT ' . implode(', ', $fields) . ' FROM ' . $q('sis_account_setup') .
            ' WHERE ' . $q('id') . ' = ?', [$id->value], 1)[0] ?? null;
        if ($row !== null) {
            $row['has_notice'] = (int) $row['has_notice'];
        }
        return $row;
    }

    public function redactNotice(EntityId $id, string $tokenHash, string $state, string $expiresAt): bool
    {
        if (!in_array($state, ['queued', 'sending'], true)) {
            throw new \InvalidArgumentException('Only expired pending notices can be redacted.');
        }
        $q = $this->sql->quote(...);
        return $this->sql->execute('UPDATE ' . $q('sis_account_setup') . ' SET ' .
            $q('encrypted_notice') . ' = NULL, ' . $q('state') . ' = ? WHERE ' .
            $q('id') . ' = ? AND ' . $q('token_hash') . ' = ? AND ' . $q('state') . ' = ? AND ' .
            $q('expires_at') . ' = ? AND ' . $q('consumed_at') . ' IS NULL', [
                $state === 'sending' ? 'uncertain' : 'expired', $id->value, $tokenHash, $state, $expiresAt,
            ]) === 1;
    }
    /** @param array<string,scalar|null> $changes */
    private function changeUnused(EntityId $id, array $changes): void
    {
        $q = $this->sql->quote(...);
        $assignments = array_map(static fn(string $key): string => $q($key) . ' = ?', array_keys($changes));
        if (
            $this->sql->execute(
                'UPDATE ' . $q('sis_account_setup') . ' SET ' . implode(', ', $assignments)
                . ' WHERE ' . $q('id') . ' = ? AND ' . $q('consumed_at') . ' IS NULL',
                [...array_values($changes),
                $id->value]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('This setup link was used.');
        }
    }
}
