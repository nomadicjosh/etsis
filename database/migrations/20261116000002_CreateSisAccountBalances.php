<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAccountBalances extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Account reporting needs a database connection.');
        }
        if (!$this->schema()->hasTable('sis_account_balances', clear: true)) {
            $this->schema()->create('sis_account_balances', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
                $table->integer('balance_minor')->notNull();
                $table->integer('position')->notNull();
                foreach (\Infrastructure\Projection\SqlAccountBalanceProjection::ENTRY_TOTALS as $column) {
                    // NULL is explicitly unverified during upgrade, never an assumed zero.
                    $table->integer($column)->size('big');
                }
                $table->foreign('id', 'sis_account_balance_fk')->references('sis_student_accounts', 'id');
            });
        }
        new \Infrastructure\EventSourcing\AccountBalanceProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()['sis.history_codec'] ?? null
        )->run();
    }

    public function down(): void
    {
        throw new LogicException('Preserve financial projections; plan a verified offline rebuild explicitly.');
    }
}
