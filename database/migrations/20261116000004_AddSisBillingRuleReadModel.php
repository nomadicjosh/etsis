<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Infrastructure\Projection\SqlBillingRuleProjection;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisBillingRuleReadModel extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Billing rule lookup needs a database connection.');
        }
        $columns = $this->schema()->getColumns('sis_billing_rules', clear: true);
        if (!is_array($columns)) {
            throw new LogicException('The existing billing rule metadata is required.');
        }
        foreach (SqlBillingRuleProjection::FIELDS as $field) {
            if (in_array($field, $columns, true)) {
                continue;
            }
            $this->schema()->alter('sis_billing_rules', static function (AlterTable $table) use ($field): void {
                match ($field) {
                    'name' => $table->string($field, 191),
                    'kind' => $table->string($field, 10),
                    'pricing_basis' => $table->string($field, 24),
                    'currency' => $table->string($field, 3),
                    default => $table->integer($field),
                };
            });
        }
        new FinancialProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()['sis.history_codec'] ?? null
        )->run('billing_rule');
        // Add the lookup index only after successful reconstruction; failed conversions can be resumed.
        $this->schema()->alter('sis_billing_rules', static function (AlterTable $table): void {
            $table->index(['term_id', 'retired', 'id'], 'sis_billing_rule_lookup');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve financial read models; plan a verified offline rebuild explicitly.');
    }
}
