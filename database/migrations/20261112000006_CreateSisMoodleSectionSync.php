<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleSectionSync extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_moodle_section_links", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("binding_id", 26)->notNull();
            $t->string("section_id", 26)->notNull();
            $t->string("moodle_course_id", 20)->notNull();
            $t->string("approved_by", 26)->notNull();
            $t->string("approved_at", 32)->notNull();
            $t->text("encrypted_review")->notNull();
            $t->integer("version")->notNull()->defaultValue(0);
            $t->integer("lease_until")->notNull()->defaultValue(0);
            $t->integer("cycle_at")->notNull()->defaultValue(0);
            $t->string("after_person", 26);
            $t->string("issue_code", 40)->notNull()->defaultValue("none");
            $t->unique(["binding_id", "section_id"], "moodle_section_scope_uq");
            $t->unique(["binding_id", "moodle_course_id"], "moodle_section_course_uq");
            $t->index(["binding_id", "cycle_at", "id"], "moodle_section_work_idx");
            $t->foreign("binding_id", "moodle_section_binding_fk")->references("sis_moodle_bindings", "id");
            $t->foreign("section_id", "moodle_section_source_fk")->references("sis_sections", "id");
        });
        $this->schema()->create("sis_moodle_section_members", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("link_id", 26)->notNull();
            $t->string("person_id", 26)->notNull();
            $t->string("enrollment_id", 26);
            $t->string("source_hash", 64);
            $t->string("issue_code", 40)->notNull()->defaultValue("none");
            $t->unique(["link_id", "person_id"], "moodle_member_scope_uq");
            $t->foreign("link_id", "moodle_member_link_fk")->references("sis_moodle_section_links", "id");
            $t->foreign("person_id", "moodle_member_person_fk")->references("sis_persons", "id");
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain approved section mappings and managed-membership references.");
    }
}
