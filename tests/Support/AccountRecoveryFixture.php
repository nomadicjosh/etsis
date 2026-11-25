<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\Operations\MaintenanceMode;
use Domain\Shared\Operations\MaintenanceState;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Operations\LocalAccountProjectionStageStorage;
use Infrastructure\Projection\SqlAccountProjectionRecovery;

final class AccountRecoveryFixture implements MaintenanceMode
{
    public \SisFixture $sis;
    public StudentAccount $account;
    public LocalAccountProjectionStageStorage $storage;
    public string $directory;
    public string $mode = 'down';

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = new \SisFixture();
        $this->account = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $this->account->post(
            EntityId::generate(),
            EntryType::Charge,
            new \Domain\Finance\ValueObject\Money(10000, 'USD'),
            $s->access->actor,
            staffRecorded: true
        );
        $s->saveAccount($this->account);
        $this->account->correct(
            new EntityId($this->account->entries()[0]['operation_id']),
            EntityId::generate(),
            new \Domain\Finance\ValueObject\Money(12000, 'USD'),
            $s->access->actor,
            str_repeat('a', 64),
            null
        );
        $s->saveAccount($this->account);
        $this->directory = sys_get_temp_dir() . '/etsis-account-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->storage = new LocalAccountProjectionStageStorage(
            $s->dropCipher,
            $this->directory,
            dirname(__DIR__, 2) . '/public'
        );
    }

    public function service(
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null,
    ): SqlAccountProjectionRecovery {
        $s = $this->sis;
        return new SqlAccountProjectionRecovery(
            $s->connection->queryBuilder(),
            $history ?? \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->transactions,
            $this->storage,
            $s->dropCipher,
            $s->clock,
            $this
        );
    }

    public function stage(): EntityId
    {
        return new EntityId($this->service()->stage($this->account->aggregateId())['stage_reference']);
    }

    public function projections(): array
    {
        $s = $this->sis;
        $id = (string) $this->account->aggregateId();
        return [$s->sql->rows('sis_account_balances', ['id' => $id]),
            $s->sql->rows('sis_account_entry_heads', ['id' => $id]),
            $s->sql->rows('sis_account_entries', ['account_id' => $id])];
    }

    public function facts(): array
    {
        return array_map($this->sis->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_student_accounts', 'sis_audit']);
    }

    public function damage(): void
    {
        $s = $this->sis;
        $s->sql->update(
            'sis_account_balances',
            ['balance_minor' => 999],
            ['id' => (string) $this->account->aggregateId()]
        );
        $s->sql->execute(
            'DELETE FROM sis_account_entries WHERE account_id = ?',
            [(string) $this->account->aggregateId()]
        );
    }

    public function close(): void
    {
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function status(): MaintenanceState
    {
        return match ($this->mode) {
            'down' => MaintenanceState::down(300, $this->sis->clock->now()),
            'up' => MaintenanceState::up(),
            default => MaintenanceState::unavailable(),
        };
    }

    public function down(int $retryAfter = 300): MaintenanceState
    {
        $this->mode = 'down';
        return $this->status();
    }

    public function up(): MaintenanceState
    {
        $this->mode = 'up';
        return $this->status();
    }
}
