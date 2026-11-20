<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPaymentMethodReports extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Payment-method projections require a database connection.');
        }
        if (!in_array('payment_method', $this->schema()->getColumns('sis_account_entries', clear: true), true)) {
            $this->schema()->alter('sis_account_entries', static function (AlterTable $t): void {
                $t->string('payment_method', 24);
            });
        }
        new FinancialProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()['sis.history_codec'] ?? null,
        )->run('student_account', entries: true, paymentMethods: true);
    }

    public function down(): void
    {
        throw new LogicException('Keep verified payment-method projections; use a deliberate offline downgrade.');
    }
}
