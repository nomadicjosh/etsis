<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseDropTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_term_drop_policies", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("term_id", 26)->unique()->notNull();
            $table->integer("version")->notNull();
            $table->text("windows")->notNull();
            $table->foreign("term_id", "stdp_term_fk")->references("sis_terms", "id");
        });
        $this->schema()->create("sis_course_drop_receipts", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("student_id", 26)->notNull();
            $table->string("section_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            // Archived references intentionally outlive grace-period deletion of academic rows.
            $table->string("enrollment_id", 26)->notNull();
            $table->integer("version")->notNull();
            $table->string("request_hash", 64)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->string("created_at", 26)->notNull();
            $table->unique(["enrollment_id", "version"], "scdr_enrollment_version");
            $table->foreign("student_id", "scdr_student_fk")->references("sis_students", "id");
            $table->foreign("section_id", "scdr_section_fk")->references("sis_sections", "id");
            $table->foreign("actor_id", "scdr_actor_fk")->references("users", "user_id");
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_course_drop_receipts");
        $this->schema()->drop("sis_term_drop_policies");
    }
}
