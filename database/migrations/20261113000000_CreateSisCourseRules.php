<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCourseRules extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_course_rules", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("rule_id", 26)->notNull();
            $t->string("code", 80)->notNull();
            $t->string("title", 191)->notNull();
            $t->integer("revision")->notNull();
            $t->text("definition_json")->size("medium")->notNull();
            $t->string("definition_hash", 64)->notNull();
            $t->string("created_at", 27)->notNull();
            $t->string("created_by", 36)->notNull();
            $t->unique(["rule_id", "revision"], "scr_rule_revision");
            $t->unique(["code", "revision"], "scr_code_revision");
            $t->index(["revision", "id"], "scr_root_page");
            $t->foreign("created_by", "scr_author_fk")->references("users", "user_id");
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve saved course-rule revisions; restore a verified backup for rollback.",
        );
    }
}
