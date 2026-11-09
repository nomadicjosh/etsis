<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseVersionBindings extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_course_versions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("course_id", 26)->notNull();
            $t->integer("schema_version")->notNull();
            $t->string("code", 80)->notNull();
            $t->string("title", 191)->notNull();
            $t->integer("credits_milli")->notNull();
            $t->string("transcript_title", 80);
            $t->text("description")->size("medium");
            $t->text("prerequisites")->notNull();
            $t->foreign("course_id", "scv_course_fk")->references("sis_courses", "id");
        });
        $this->schema()->create("sis_section_course_versions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("course_version_id", 26)->notNull();
            $t->foreign("id", "sscv_section_fk")->references("sis_sections", "id");
            $t->foreign("course_version_id", "sscv_version_fk")->references("sis_course_versions", "id");
        });
        $this->schema()->create("sis_credit_course_versions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("course_version_id", 26)->notNull();
            $t->foreign("id", "sccv_credit_fk")
                ->references("sis_academic_credits", "id")
                ->onDelete("CASCADE");
            $t->foreign("course_version_id", "sccv_version_fk")->references("sis_course_versions", "id");
        });
    }

    public function down(): void
    {
        $tables = ["sis_credit_course_versions", "sis_section_course_versions", "sis_course_versions"];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
