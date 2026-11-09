<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentHiatusTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_student_hiatuses", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("student_id", 26)->notNull();
            $table->string("code", 8)->notNull();
            $table->string("starts_on", 10)->notNull();
            $table->string("ends_on", 10);
            $table->integer("voided")->notNull();
            $table->integer("version")->notNull();
            $table->index(["student_id", "id"], "ssh_student_page");
            $table->index(["student_id", "code", "voided", "starts_on"], "ssh_overlap");
            $table->foreign("student_id", "ssh_student_fk")->references("sis_students", "id");
        });
        $this->schema()->create("sis_student_hiatus_actions", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("hiatus_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->string("action", 24)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->string("created_at", 26)->notNull();
            $table->index(["hiatus_id", "id"], "sha_history_page");
            $table->index(["hiatus_id", "action"], "sha_return_lookup");
            $table->foreign("hiatus_id", "sha_hiatus_fk")->references("sis_student_hiatuses", "id");
            $table->foreign("actor_id", "sha_actor_fk")->references("users", "user_id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_student_hiatus_actions");
        $this->schema()->drop("sis_student_hiatuses");
    }
}
