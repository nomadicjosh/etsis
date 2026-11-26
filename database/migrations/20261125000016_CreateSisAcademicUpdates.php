<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAcademicUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_academic_updates", self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("owner_user_id", 36)->notNull();
        $fields = ["person_id", "student_id", "credit_id", "section_id", "enrollment_id"];
        foreach ($fields as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer("revision")->notNull();
        $t->string("status", 12)->notNull();
        $t->string("recorded_at", 27)->notNull();
        $t->string("read_at", 27);
        $t->unique(["credit_id"], "sagn_credit_unique");
        $t->index(["owner_user_id", "read_at", "recorded_at", "id"], "sagn_owner_page");
        $t->foreign("person_id", "sagn_person_fk")->references("sis_persons", "id");
        $t->foreign("student_id", "sagn_student_fk")->references("sis_students", "id");
        $t->foreign("section_id", "sagn_section_fk")->references("sis_sections", "id");
        $t->foreign("credit_id", "sagn_credit_fk")->references("sis_academic_credits", "id")->onDelete("CASCADE");
    }

    public function down(): void
    {
        $this->schema()->drop("sis_academic_updates");
    }
}
