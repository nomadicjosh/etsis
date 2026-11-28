<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleGradeImports extends Migration
{
    public function up(): void
    {
        if (
            !$this->schema()->hasTable("sis_moodle_grade_imports", clear: true)
        ) {
            $this->schema()->create("sis_moodle_grade_imports", [
                self::class,
                "jobs",
            ]);
        }
        if (
            !$this->schema()->hasTable(
                "sis_moodle_grade_candidates",
                clear: true,
            )
        ) {
            $this->schema()->create("sis_moodle_grade_candidates", [
                self::class,
                "candidates",
            ]);
        }
        if (
            !$this->schema()->hasTable("sis_moodle_grade_notices", clear: true)
        ) {
            $this->schema()->create("sis_moodle_grade_notices", [
                self::class,
                "notices",
            ]);
        }
    }

    public static function jobs(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        foreach (["request_id", "requested_by", "binding_id"] as $name) {
            $t->string($name, 26)->notNull();
        }
        foreach (["after_enrollment", "through_enrollment"] as $name) {
            $t->string($name, 26);
        }
        $t->string("state", 20)->notNull();
        $t->string("scope_hash", 64)->notNull();
        $t->string("requested_at", 32)->notNull();
        $t->string("completed_at", 32);
        $t->string("issue", 40)->notNull()->defaultValue("none");
        $t->integer("revision")->notNull()->defaultValue(1);
        $t->integer("lease_until")->notNull()->defaultValue(0);
        $t->integer("processed")->notNull()->defaultValue(0);
        $t->integer("ready")->notNull()->defaultValue(0);
        $t->text("encrypted_context")->notNull();
        $t->index(["state", "lease_until", "id"], "mgi_pending");
        $t->foreign("id", "mgi_section_fk")->references("sis_sections", "id");
        $t->foreign("binding_id", "mgi_binding_fk")->references(
            "sis_moodle_bindings",
            "id",
        );
        $t->foreign("requested_by", "mgi_actor_fk")->references(
            "users",
            "user_id",
        );
    }

    public static function candidates(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        foreach (
            [
                "request_id",
                "section_id",
                "student_id",
                "enrollment_id",
                "person_id",
            ] as $name
        ) {
            $t->string($name, 26)->notNull();
        }
        $t->string("source_key", 64)->notNull()->unique();
        $t->string("source_hash", 64)->notNull();
        $t->string("state", 20)->notNull();
        $t->string("observed_at", 32)->notNull();
        $t->integer("revision")->notNull()->defaultValue(1);
        $t->text("encrypted_source")->notNull();
        $t->string("operation_id", 26);
        $t->string("accepted_hash", 64);
        $t->text("encrypted_result");
        $t->index(["section_id", "id"], "mgc_section_page");
        $t->index(["enrollment_id", "state"], "mgc_source_state");
        $t->foreign("section_id", "mgc_section_fk")->references(
            "sis_sections",
            "id",
        );
        $t->foreign("student_id", "mgc_student_fk")->references(
            "sis_students",
            "id",
        );
        $t->foreign("person_id", "mgc_person_fk")->references(
            "sis_persons",
            "id",
        );
    }

    public static function notices(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("owner_id", 26)->notNull();
        $t->string("section_id", 26)->notNull();
        $t->string("state", 20)->notNull();
        $t->integer("processed")->notNull();
        $t->integer("ready")->notNull();
        $t->integer("revision")->notNull()->defaultValue(1);
        $t->string("recorded_at", 32)->notNull();
        $t->string("read_at", 32);
        $t->index(["owner_id", "id"], "mgn_owner_page");
        $t->foreign("owner_id", "mgn_owner_fk")->references("users", "user_id");
        $t->foreign("section_id", "mgn_section_fk")->references(
            "sis_sections",
            "id",
        );
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain accepted Moodle source references and grade-decision receipts.",
        );
    }
}
