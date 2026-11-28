<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Infrastructure\Persistence\SqlRecords;

final class CreateSisPaymentWork extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Payment work requires a database connection.');
        }
        $sql = new SqlRecords($adapter->connection()->queryBuilder());
        if (!$this->schema()->hasTable('sis_payment_work', clear: true)) {
            $q = $sql->quote(...);
            if (
                $sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_portal_payments')
                . ' WHERE ' . $q('status') . ' IN (?, ?)', ['pending', 'expired'], 1) !== []
            ) {
                throw new LogicException(
                    'Reconcile unfinished automatic-capture sessions before enabling manual capture.'
                );
            }
            $this->schema()->create('sis_payment_work', [self::class, 'work']);
        }
    }

    public static function work(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('version')->notNull();
        $t->string('state', 16)->notNull();
        $t->integer('due_at')->notNull();
        $t->integer('lease_until')->notNull();
        $t->index(['state', 'due_at', 'id'], 'payment_work_due');
    }

    public function down(): void
    {
        throw new LogicException('Reconcile retained payment work before explicit disposition.');
    }
}
