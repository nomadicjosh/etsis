<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisProgramSites extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "additional_campuses_recorded",
                $this->schema()->getColumns("sis_program_catalog_details", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_program_catalog_details", static function (AlterTable $t): void {
                // Earlier publications did not describe additional sites; absence is not an explicit empty list.
                $t->integer("additional_campuses_recorded")->notNull()->defaultValue(0);
            });
        }
        if (!$this->schema()->hasTable("sis_program_sites", clear: true)) {
            $this->schema()->create("sis_program_sites", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("program_id", 26)->notNull();
                $t->string("campus_id", 26)->notNull();
                $t->unique(["program_id", "campus_id"], "spsite_program_site_unique");
                $t->foreign("program_id", "spsite_program_fk")->references("sis_programs", "id");
                $t->foreign("campus_id", "spsite_campus_fk")->references("sis_catalog_classifications", "id");
            });
        }
        if (!$this->schema()->hasTable("sis_program_catalog_sites", clear: true)) {
            $this->schema()->create("sis_program_catalog_sites", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("catalog_id", 26)->notNull();
                $t->string("campus_id", 26)->notNull();
                $t->string("campus_code", 40)->notNull();
                $t->string("campus_title", 191)->notNull();
                $t->unique(["catalog_id", "campus_id"], "spcsite_catalog_site_unique");
                $t->foreign("catalog_id", "spcsite_catalog_fk")->references("sis_program_catalog_details", "id");
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Preserve published program locations and unknown earlier affiliations.");
    }
}
