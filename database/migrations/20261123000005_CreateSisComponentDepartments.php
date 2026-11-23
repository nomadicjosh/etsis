<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisComponentDepartments extends Migration
{
    public function up(): void
    {
        if (
            !$this->schema()->hasTable("sis_component_departments", clear: true)
        ) {
            $this->schema()->create(
                "sis_component_departments",
                static function (CreateTable $t): void {
                    $t->string("id", 26)->notNull()->primary();
                    $t->string("component_id", 26)->notNull();
                    $t->string("department_id", 26)->notNull();
                    $t->unique(
                        ["component_id", "department_id"],
                        "scd_owner_unique",
                    );
                    $t->foreign("component_id", "scd_component_fk")->references(
                        "sis_program_specializations",
                        "id",
                    );
                    $t->foreign(
                        "department_id",
                        "scd_department_fk",
                    )->references("sis_catalog_classifications", "id");
                },
            );
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve explicit joint curriculum ownership.",
        );
    }
}
