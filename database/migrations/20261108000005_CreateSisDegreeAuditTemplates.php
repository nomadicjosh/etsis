<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisDegreeAuditTemplates extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_degree_audit_templates", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("program_id", 26)->notNull();
            $t->string("academic_year_id", 26)->notNull();
            $t->integer("revision")->notNull();
            $t->text("draft_json")->size("medium")->notNull();
            $t->string("published_version_id", 26);
            $t->integer("publication_position")->notNull();
            $t->unique(["program_id", "academic_year_id"], "sdat_program_year");
            $t->index(["program_id", "id"], "sdat_program_page");
            $t->foreign("program_id", "sdat_program_fk")->references("sis_programs", "id");
            $t->foreign("academic_year_id", "sdat_year_fk")->references("sis_academic_years", "id");
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_degree_audit_templates");
    }
}
