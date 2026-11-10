<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseSubjects extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_subjects", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("code", 40)->unique("subject_code_uq")->notNull();
            $t->string("title", 191)->notNull();
            $t->boolean("active")->notNull()->defaultValue(true);
        });
        $this->schema()->create("sis_course_subjects", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("subject_id", 26)->notNull();
            $t->foreign("id", "course_subject_course_fk")->references("sis_courses", "id");
            $t->foreign("subject_id", "course_subject_ref_fk")->references("sis_subjects", "id");
        });
        $this->schema()->create("sis_course_version_subjects", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("subject_id", 26)->notNull();
            $t->string("code", 40)->notNull();
            $t->string("title", 191)->notNull();
            $t->foreign("id", "cv_subject_version_fk")->references("sis_course_versions", "id");
        });
    }

    public function down(): void
    {
        throw new LogicException("Retain published subject references and historical classifications.");
    }
}
