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
