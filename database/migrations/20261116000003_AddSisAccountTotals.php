<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\AccountBalanceProjectionUpgrade;
use Infrastructure\Projection\SqlAccountBalanceProjection;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAccountTotals extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Account totals need a database connection.');
        }
        $columns = $this->schema()->getColumns('sis_account_balances', clear: true);
        if (!is_array($columns)) {
            throw new LogicException('The existing account balance projection is required.');
        }
        foreach (SqlAccountBalanceProjection::ENTRY_TOTALS as $column) {
            if (!in_array($column, $columns, true)) {
                $this->schema()->alter('sis_account_balances', static function (AlterTable $table) use ($column): void {
                    $table->integer($column)->size('big');
                });
            }
        }
        new AccountBalanceProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()['sis.history_codec'] ?? null
        )->run();
    }

    public function down(): void
    {
        throw new LogicException('Preserve financial projections; plan a verified offline rebuild explicitly.');
    }
}
