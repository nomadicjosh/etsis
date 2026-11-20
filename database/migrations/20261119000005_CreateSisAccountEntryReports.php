<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAccountEntryReports extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Posting reporting requires a connection-aware migration.');
        }
        if (!$this->schema()->hasTable('sis_account_entries', clear: true)) {
            $this->schema()->create('sis_account_entries', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('account_id', 26)->notNull();
                $t->string('operation_id', 26)->notNull();
                $t->string('type', 16)->notNull();
                $t->integer('amount_minor')->size('big')->notNull();
                $t->integer('effect_minor')->size('big')->notNull();
                $t->string('currency', 3)->notNull();
                $t->boolean('reversed')->notNull();
                $t->string('recorded_at', 19)->notNull();
                $t->integer('posted_position')->notNull();
                $t->string('origin', 24)->notNull();
                $t->string('payment_method', 24);
                foreach (['corrects_operation_id', 'corrected_by_operation_id', 'source_operation_id'] as $field) {
                    $t->string($field, 26);
                }
                $t->unique(['account_id', 'operation_id'], 'sae_account_operation');
                $t->index(['account_id', 'type', 'reversed', 'recorded_at', 'id'], 'sae_account_date');
                $t->index(['type', 'reversed', 'recorded_at', 'id'], 'sae_posting_page');
                $t->foreign('account_id', 'sae_account_fk')->references('sis_student_accounts', 'id');
            });
        }
        if (!$this->schema()->hasTable('sis_account_entry_heads', clear: true)) {
            $this->schema()->create('sis_account_entry_heads', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->integer('position')->notNull();
                $t->integer('entry_count')->notNull();
                $t->foreign('id', 'saeh_account_fk')->references('sis_student_accounts', 'id');
            });
        }
        new \Infrastructure\EventSourcing\FinancialProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()['sis.history_codec'] ?? null,
        )->run('student_account', entries: true);
    }

    public function down(): void
    {
        throw new LogicException('Keep verified posting read models; plan an explicit offline rebuild or downgrade.');
    }
}
