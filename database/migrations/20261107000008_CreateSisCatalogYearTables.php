<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCatalogYearTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_catalog_years", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("code", 4)->unique("scy_code_unique")->notNull();
            $table->string("title", 80)->notNull();
            $table->string("starts_on", 10)->notNull();
            $table->string("ends_on", 10)->notNull();
            $table->integer("published")->notNull();
        });
        $this->schema()->create("sis_program_catalogs", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("program_id", 26)->notNull();
            $table->string("year_id", 26)->notNull();
            $table->integer("revision")->notNull();
            $table->integer("published")->notNull();
            $table->integer("is_current")->notNull();
            $table->string("program_code", 80)->notNull();
            $table->string("program_title", 191)->notNull();
            $table->unique(["program_id", "year_id", "revision"], "spc_program_revision");
            $table->index(["program_id", "published", "is_current", "id"], "spc_program_page");
            $table->foreign("program_id", "spc_program_fk")->references("sis_programs", "id");
            $table->foreign("year_id", "spc_year_fk")->references("sis_catalog_years", "id");
        });
        $this->schema()->create("sis_student_catalogs", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("assignment_id", 26)->unique("ssc_assignment_unique")->notNull();
            $table->string("catalog_id", 26)->notNull();
            $table->integer("version")->notNull();
            $table->foreign("assignment_id", "ssc_assignment_fk")->references("sis_student_programs", "id");
            $table->foreign("catalog_id", "ssc_catalog_fk")->references("sis_program_catalogs", "id");
        });
        $this->schema()->create("sis_student_catalog_changes", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("assignment_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->string("previous_catalog_id", 26);
            $table->string("catalog_id", 26)->notNull();
            $table->text("encrypted_note")->size("medium");
            $table->string("created_at", 26)->notNull();
            $table->index(["assignment_id", "id"], "sscc_history_page");
            $table->foreign("assignment_id", "sscc_assignment_fk")->references("sis_student_programs", "id");
            $table->foreign("actor_id", "sscc_actor_fk")->references("users", "user_id");
            $table->foreign("previous_catalog_id", "sscc_previous_fk")->references("sis_program_catalogs", "id");
            $table->foreign("catalog_id", "sscc_catalog_fk")->references("sis_program_catalogs", "id");
        });
    }

    public function down(): void
    {
        $tables = ["sis_student_catalog_changes", "sis_student_catalogs", "sis_program_catalogs", "sis_catalog_years"];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
