<?php

declare(strict_types=1);

use Infrastructure\EventSourcing\FinancialProjectionUpgrade;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisHistoricalAccountReports extends Migration
{
    public function up(): void
    {
        foreach (
            [
                "sis_account_entries" => [
                    "reversed_at" => "date",
                    "reversed_position" => "position",
                ],
                "sis_account_entry_heads" => [
                    "opened_at" => "date",
                    "temporal_position" => "position",
                ],
            ] as $name => $fields
        ) {
            foreach ($fields as $field => $type) {
                if (in_array($field, $this->schema()->getColumns($name, clear: true), true)) {
                    continue;
                }
                $this->schema()->alter($name, static function (AlterTable $table) use (
                    $field,
                    $type,
                ): void {
                    if ($type === "date") {
                        $table->string($field, 19);
                    } else {
                        $table->integer($field);
                    }
                });
            }
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Historical account reports require a database connection.");
        }
        new FinancialProjectionUpgrade(
            $adapter->connection()->queryBuilder(),
            $this->getObjectMap()["sis.history_codec"] ?? null,
        )->run("student_account", entries: true, temporal: true);
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve the verified financial chronology and historical reports.",
        );
    }
}
