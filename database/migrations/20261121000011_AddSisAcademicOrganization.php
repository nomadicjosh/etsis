<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAcademicOrganization extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Academic organization requires a database connection.");
        }
        foreach (
            [
                ["sis_catalog_classifications", "school_id", "scc_school_fk"],
                ["sis_program_specializations", "department_id", "sps_dept_fk"],
            ] as [$table, $column, $constraint]
        ) {
            if (in_array($column, $this->schema()->getColumns($table, clear: true), true)) {
                continue;
            }
            if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
                $adapter
                    ->connection()
                    ->command(
                        'ALTER TABLE "' .
                            $table .
                            '" ADD COLUMN "' .
                            $column .
                            '" VARCHAR(26) REFERENCES "sis_catalog_classifications" ("id")',
                    );
            } else {
                $this->schema()->alter($table, static function (AlterTable $t) use (
                    $column,
                    $constraint,
                ): void {
                    $t->string($column, 26);
                    $t->foreign($column, $constraint)->references(
                        "sis_catalog_classifications",
                        "id",
                    );
                });
            }
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve academic organization references and published ownership facts.",
        );
    }
}
