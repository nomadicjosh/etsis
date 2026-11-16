<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisBillingPricing extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Billing pricing needs a database connection.");
        }
        $columns = $this->schema()->getColumns("sis_billing_rules", clear: true);
        if (!is_array($columns)) {
            throw new LogicException("Billing rule metadata is required.");
        }
        if (!in_array("pricing_basis", $columns, true)) {
            $this->schema()->alter("sis_billing_rules", static function (AlterTable $table): void {
                $table->string("pricing_basis", 24);
            });
        }
        // Restore the known flat basis of earlier definitions without rewriting any recorded decision.
        new FinancialProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()["sis.history_codec"] ?? null,
        )->run("billing_rule");
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve recorded prices and plan an explicit verified conversion.",
        );
    }
}
