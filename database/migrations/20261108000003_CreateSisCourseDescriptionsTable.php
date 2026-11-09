<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseDescriptionsTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_course_descriptions", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("course_id", 26)->unique("scd_course_unique")->notNull();
            $t->string("transcript_title", 80);
            $t->text("description")->size("medium");
            $t->string("published_transcript_title", 80);
            $t->text("published_description")->size("medium");
            $t->string("publication_version_id", 26);
            $t->integer("published_position");
            $t->foreign("course_id", "scd_course_fk")->references("sis_courses", "id");
        });
    }

    public function down(): void
    {
        // Development rollback removes drafts and their public projection; publication events remain.
        $this->schema()->drop("sis_course_descriptions");
    }
}
