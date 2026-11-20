<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisProgramCampuses extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Program campus setup requires a database connection.");
        }
        if (!in_array("campus_id", $this->schema()->getColumns("sis_program_details", clear: true), true)) {
            if ($adapter->connection()->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite") {
                // Add a nullable reference in place; do not rebuild or relabel existing program rows.
                $adapter
                    ->connection()
                    ->command(
                        'ALTER TABLE "sis_program_details" ADD COLUMN "campus_id" ' .
                            'VARCHAR(26) REFERENCES "sis_catalog_classifications" ("id")',
                    );
            } else {
                $this->schema()->alter("sis_program_details", static function (AlterTable $t): void {
                    $t->string("campus_id", 26);
                    $t->foreign("campus_id", "spd_campus_fk")->references(
                        "sis_catalog_classifications",
                        "id",
                    );
                });
            }
        }
        foreach (["id" => 26, "code" => 40, "title" => 191] as $suffix => $length) {
            $column = "campus_" . $suffix;
            if (
                !in_array(
                    $column,
                    $this->schema()->getColumns("sis_program_catalog_details", clear: true),
                    true,
                )
            ) {
                $this->schema()->alter("sis_program_catalog_details", static function (AlterTable $t) use (
                    $column,
                    $length,
                ): void {
                    $t->string($column, $length);
                });
            }
        }
        if (
            !in_array(
                "campus_recorded",
                $this->schema()->getColumns("sis_program_catalog_details", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_program_catalog_details", static function (AlterTable $t): void {
                // Earlier publications did not record a program campus. An unknown is not an explicit blank.
                $t->integer("campus_recorded")->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Preserve published program campuses and unknown earlier locations.");
    }
}
