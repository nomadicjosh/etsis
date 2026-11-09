<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseGroupLibrary extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_course_groups", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("name", 191)->notNull();
            $t->string("creation_hash", 64)->notNull();
            $t->text("draft_json")->size("medium")->notNull();
            $t->integer("revision")->notNull();
            $t->string("published_version_id", 26);
            $t->string("published_name", 191);
            $t->integer("publication_position")->notNull();
            $t->index(["id", "name"], "scg_library_page");
            // Groups intentionally have no program, degree or academic-year ownership.
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_course_groups");
    }
}
