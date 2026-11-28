<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPaymentGateways extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Payment setup requires a database connection.');
        }
        $sql = new SqlRecords($adapter->connection()->queryBuilder());
        if (!in_array('sealed_gateway', $this->schema()->getColumns('sis_portal_payments', clear: true), true)) {
            $q = $sql->quote(...);
            if (
                $sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_portal_payments') . ' WHERE '
                . $q('status') . ' IN (?, ?)', ['pending', 'expired'], 1) !== []
            ) {
                throw new LogicException('Reconcile earlier unfinished checkouts before upgrading payment setup. '
                    . 'Their merchant credentials and clearing assignment cannot be inferred.');
            }
        }
        foreach (
            ['sis_payment_connections' => 'connections', 'sis_payment_routes' => 'routes',
            'sis_payment_reviews' => 'reviews'] as $table => $method
        ) {
            if (!$this->schema()->hasTable($table, clear: true)) {
                $this->schema()->create($table, [self::class, $method]);
            }
        }
        foreach (['sis_ledger_accounts' => 'kind', 'sis_portal_payments' => 'sealed_gateway'] as $table => $column) {
            if (!in_array($column, $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($column): void {
                    if ($column === 'kind') {
                        $t->string('kind', 16);
                    } else {
                        $t->text('sealed_gateway');
                    }
                });
            }
        }
        // Earlier sessions remain explicit, unbound history. They are never assigned today's credentials.
        $permission = $sql->rows('sis_access_permissions', ['name' => 'sis:payments:configure'], 1)[0] ?? null;
        if ($permission !== null && (int) $permission['is_system'] !== 1) {
            throw new LogicException('Conflicting payment setup permission.');
        }
        $id = $permission['id'] ?? EntityId::generate()->value;
        if ($permission === null) {
            $sql->insert('sis_access_permissions', ['id' => $id, 'name' => 'sis:payments:configure',
                'description' => 'Review named payment connections and purpose routing with a finance ledger duty.',
                'is_system' => 1]);
        }
        if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
            $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                'ancestor_id' => $id, 'descendant_id' => $id]);
        }
    }

    public static function connections(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('version')->notNull();
        $t->text('sealed_data')->notNull();
    }

    public static function routes(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('purpose', 16)->notNull();
        $t->string('currency', 3)->notNull();
        $t->integer('version')->notNull();
        $t->text('sealed_data')->notNull();
        $t->unique(['purpose', 'currency'], 'payment_route_purpose');
    }

    public static function reviews(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('actor_id', 26)->notNull();
        $t->string('revision', 64)->notNull();
        $t->integer('expires_at')->notNull();
        $t->text('sealed_data');
        $t->text('sealed_result');
        $t->index(['actor_id', 'expires_at'], 'payment_review_expiry');
    }

    public function down(): void
    {
        throw new LogicException('Payment setup and retained checkout evidence require explicit disposition.');
    }
}
