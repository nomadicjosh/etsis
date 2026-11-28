<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Payments\Repository\GatewayRepository;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGatewayRepository implements GatewayRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function connection(EntityId $id, bool $lock = false): ?array
    {
        return $this->row('sis_payment_connections', $id, $lock);
    }

    public function connections(?EntityId $after = null): array
    {
        return $this->page('sis_payment_connections', $after);
    }

    public function saveConnection(EntityId $id, int $version, string $sealed): void
    {
        $this->save('sis_payment_connections', $id, $version, ['sealed_data' => $sealed]);
    }

    public function route(EntityId $id, bool $lock = false): ?array
    {
        return $this->row('sis_payment_routes', $id, $lock);
    }

    public function routes(?EntityId $after = null): array
    {
        return $this->page('sis_payment_routes', $after);
    }

    public function saveRoute(EntityId $id, string $purpose, string $currency, int $version, string $sealed): void
    {
        $this->save(
            'sis_payment_routes',
            $id,
            $version,
            ['purpose' => $purpose, 'currency' => $currency, 'sealed_data' => $sealed]
        );
    }

    public function asset(EntityId $id, bool $lock = false): array
    {
        return $this->row('sis_ledger_accounts', $id, $lock) ?? throw new RecordNotFoundException();
    }

    public function assets(string $currency, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT * FROM ' . $q('sis_ledger_accounts') . ' WHERE '
            . $q('currency') . ' = ?' . ($after === null ? '' : ' AND ' . $q('id') . ' > ?')
            . ' ORDER BY ' . $q('id'), $after === null ? [$currency] : [$currency, $after->value], 21);
    }

    public function codeExists(string $code): bool
    {
        return $this->sql->rows('sis_ledger_accounts', ['code' => $code], 1) !== [];
    }

    public function classify(EntityId $id, string $kind): void
    {
        $q = $this->sql->quote(...);
        if (
            $this->sql->execute('UPDATE ' . $q('sis_ledger_accounts') . ' SET ' . $q('kind') . ' = ? WHERE '
            . $q('id') . ' = ? AND ' . $q('kind') . ' IS NULL', [$kind, $id->value]) !== 1
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException();
        }
    }

    public function review(EntityId $id, bool $lock = false): array
    {
        return $this->row('sis_payment_reviews', $id, $lock) ?? throw new RecordNotFoundException();
    }

    public function stage(array $row): void
    {
        $this->sql->insert('sis_payment_reviews', $row);
    }

    public function accept(EntityId $id, string $sealed): void
    {
        $this->sql->update(
            'sis_payment_reviews',
            ['sealed_data' => null, 'sealed_result' => $sealed],
            ['id' => $id->value]
        );
    }

    public function clearExpired(int $now, int $limit): int
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_payment_reviews') . ' WHERE '
            . $q('expires_at') . ' <= ? AND ' . $q('sealed_data') . ' IS NOT NULL AND '
            . $q('sealed_result') . ' IS NULL ORDER BY ' . $q('id'), [$now], $limit);
        $count = 0;
        foreach ($rows as $row) {
            $this->sql->lock('sis_payment_reviews', $row['id'], 'id');
            $count += $this->sql->execute(
                'UPDATE ' . $q('sis_payment_reviews') . ' SET '
                . $q('sealed_data') . ' = NULL WHERE ' . $q('id') . ' = ? AND ' . $q('expires_at')
                . ' <= ? AND ' . $q('sealed_data') . ' IS NOT NULL AND ' . $q('sealed_result') . ' IS NULL',
                [$row['id'], $now]
            );
        }
        return $count;
    }

    public function prune(EntityId $actor, int $now): void
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_payment_reviews') . ' WHERE '
            . $q('actor_id') . ' = ? AND ' . $q('expires_at') . ' <= ? AND ' . $q('sealed_data')
            . ' IS NOT NULL ORDER BY ' . $q('id'), [$actor->value, $now], 10);
        foreach ($rows as $row) {
            $this->sql->update('sis_payment_reviews', ['sealed_data' => null], ['id' => $row['id']]);
        }
        $live = $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_payment_reviews') . ' WHERE '
            . $q('actor_id') . ' = ? AND ' . $q('sealed_data') . ' IS NOT NULL ORDER BY ' . $q('id'),
            [$actor->value],
            6
        );
        if (count($live) >= 5) {
            throw new \DomainException('Finish an existing payment setup review or wait twenty minutes.');
        }
    }

    private function row(string $table, EntityId $id, bool $lock): ?array
    {
        return $lock ? $this->sql->current($table, $id->value)
        : ($this->sql->rows($table, ['id' => $id->value], 1)[0] ?? null);
    }

    private function page(string $table, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q($table)
            . ($after === null ? '' : ' WHERE ' . $q('id') . ' > ?') . ' ORDER BY ' . $q('id'),
            $after === null ? [] : [$after->value],
            21
        );
    }

    private function save(string $table, EntityId $id, int $version, array $data): void
    {
        if ($version === 1) {
            $this->sql->insert($table, ['id' => $id->value, 'version' => $version] + $data);
        } else {
            if (
                $this->sql->update(
                    $table,
                    ['version' => $version] + $data,
                    ['id' => $id->value, 'version' => $version - 1]
                ) !== 1
            ) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException();
            }
        }
    }
}
