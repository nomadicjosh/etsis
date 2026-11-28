<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\PaymentRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPaymentRepository implements PaymentRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function find(EntityId $id): ?array
    {
        return $this->sql->rows('sis_portal_payments', ['id' => $id->value], 1)[0] ?? null;
    }

    public function active(EntityId $account, int $now): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT * FROM ' . $q('sis_portal_payments') . ' WHERE '
            . $q('account_id') . ' = ? AND ' . $q('status') . " = 'pending' AND " . $q('expires_at')
            . ' > ? ORDER BY ' . $q('id') . ' DESC', [$account->value, $now], 1)[0] ?? null;
    }

    public function activeFee(EntityId $fee, int $now): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT * FROM ' . $q('sis_portal_payments') . ' WHERE '
            . $q('fee_id') . ' = ? AND ' . $q('status') . " = 'pending' AND " . $q('expires_at')
            . ' > ? ORDER BY ' . $q('id') . ' DESC', [$fee->value, $now], 1)[0] ?? null;
    }

    public function add(EntityId $id, array $record): void
    {
        $this->sql->insert('sis_portal_payments', ['id' => $id->value] + $record);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_portal_payments', $id->value, 'id');
    }

    public function attach(EntityId $id, string $session, string $encryptedUrl, ?string $sealedGateway = null): void
    {
        $this->sql->update(
            'sis_portal_payments',
            ['session_id' => $session, 'encrypted_url' => $encryptedUrl]
                + ($sealedGateway === null ? [] : ['sealed_gateway' => $sealedGateway]),
            ['id' => $id->value, 'status' => 'pending']
        );
    }

    public function paid(EntityId $id, ?string $sealedGateway = null): void
    {
        $this->sql->update('sis_portal_payments', ['status' => 'paid', 'encrypted_url' => null]
            + ($sealedGateway === null ? [] : ['sealed_gateway' => $sealedGateway]), ['id' => $id->value]);
    }

    public function expire(EntityId $id): void
    {
        $this->sql->update(
            'sis_portal_payments',
            ['status' => 'expired', 'encrypted_url' => null],
            ['id' => $id->value, 'status' => 'pending']
        );
    }
    public function cancelled(EntityId $id, ?string $sealedGateway = null): void
    {
        $this->sql->update('sis_portal_payments', ['status' => 'cancelled', 'encrypted_url' => null]
            + ($sealedGateway === null ? [] : ['sealed_gateway' => $sealedGateway]), ['id' => $id->value]);
    }

    public function wake(EntityId $id, int $now): void
    {
        $this->lock($id);
        $work = $this->work($id);
        if ($work === null) {
            $this->sql->insert('sis_payment_work', ['id' => $id->value, 'version' => 0, 'state' => 'queued',
                'due_at' => $now, 'lease_until' => 0]);
        } elseif ($work['state'] !== 'running' || (int) $work['lease_until'] <= $now) {
            $this->sql->update('sis_payment_work', ['state' => 'queued', 'due_at' => $now,
                'version' => (int) $work['version'] + 1], ['id' => $id->value, 'version' => $work['version']]);
        }
    }

    public function work(EntityId $id): ?array
    {
        return $this->sql->rows('sis_payment_work', ['id' => $id->value], 1)[0] ?? null;
    }

    public function claim(int $now): ?array
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_payment_work') . ' WHERE ('
            . $q('state') . ' = ? AND ' . $q('due_at') . ' <= ?) OR (' . $q('state') . ' = ? AND '
            . $q('lease_until') . ' <= ?) ORDER BY ' . $q('due_at') . ', ' . $q('id'),
            ['queued', $now, 'running', $now],
            1
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $changes = ['state' => 'running', 'version' => (int) $row['version'] + 1, 'lease_until' => $now + 90];
        if (
            $this->sql->update(
                'sis_payment_work',
                $changes,
                ['id' => $row['id'], 'version' => $row['version']]
            ) !== 1
        ) {
            return null;
        }
        return array_replace($row, $changes);
    }

    public function finish(EntityId $id, int $version, string $state, int $dueAt): void
    {
        if (!in_array($state, ['queued', 'review', 'done'], true)) {
            throw new \InvalidArgumentException();
        }
        $this->sql->update(
            'sis_payment_work',
            ['state' => $state, 'due_at' => $dueAt, 'lease_until' => 0],
            ['id' => $id->value, 'version' => $version, 'state' => 'running']
        );
    }

    public function forTarget(string $field, EntityId $target, ?EntityId $after = null): array
    {
        if (!in_array($field, ['account_id', 'fee_id'], true)) {
            throw new \InvalidArgumentException();
        }
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_portal_payments') . ' WHERE ' . $q($field)
            . ' = ?' . ($after === null ? '' : ' AND ' . $q('id') . ' < ?') . ' ORDER BY ' . $q('id') . ' DESC',
            $after === null ? [$target->value] : [$target->value, $after->value],
            21
        );
    }
}
