<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleCourseRequests extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_moodle_course_requests", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("binding_id", 26)->notNull();
            $t->string("section_id", 26)->notNull();
            $t->string("publication_id", 26)->notNull();
            $t->string("requested_by", 26)->notNull();
            $t->string("requested_at", 32)->notNull();
            $t->text("encrypted_request")->notNull();
            $t->string("state", 20)->notNull()->defaultValue("queued");
            $t->string("issue_code", 40)->notNull()->defaultValue("none");
            $t->integer("version")->notNull()->defaultValue(0);
            $t->integer("attempts")->notNull()->defaultValue(0);
            $t->integer("lease_until")->notNull()->defaultValue(0);
            $t->unique(["binding_id", "section_id"], "moodle_course_request_scope_uq");
            $t->index(["state", "lease_until", "id"], "moodle_course_request_work_idx");
            $t->foreign("binding_id", "moodle_course_request_binding_fk")->references(
                "sis_moodle_bindings",
                "id",
            );
            $t->foreign("section_id", "moodle_course_request_section_fk")->references("sis_sections", "id");
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain original Moodle creation decisions and operation references.");
    }
}
