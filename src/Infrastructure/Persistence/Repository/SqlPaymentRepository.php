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

    public function add(EntityId $id, array $record): void
    {
        $this->sql->insert('sis_portal_payments', ['id' => $id->value] + $record);
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock('sis_portal_payments', $id->value, 'id');
    }

    public function attach(EntityId $id, string $session, string $encryptedUrl): void
    {
        $this->sql->update(
            'sis_portal_payments',
            ['session_id' => $session, 'encrypted_url' => $encryptedUrl],
            ['id' => $id->value, 'status' => 'pending']
        );
    }

    public function paid(EntityId $id): void
    {
        $this->sql->update('sis_portal_payments', ['status' => 'paid', 'encrypted_url' => null], ['id' => $id->value]);
    }

    public function expire(EntityId $id): void
    {
        $this->sql->update(
            'sis_portal_payments',
            ['status' => 'expired', 'encrypted_url' => null],
            ['id' => $id->value, 'status' => 'pending']
        );
    }
}
