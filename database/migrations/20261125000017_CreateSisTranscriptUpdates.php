<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTranscriptUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_transcript_updates", self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("owner_user_id", 36)->notNull();
        $fields = ["person_id", "student_id", "request_id", "document_id"];
        foreach ($fields as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer("revision")->notNull();
        $t->string("status", 12)->notNull();
        $t->string("recorded_at", 27)->notNull();
        $t->string("read_at", 27);
        $t->unique(["request_id"], "stnu_request_unique");
        $t->index(["owner_user_id", "read_at", "recorded_at", "id"], "stnu_owner_page");
        $t->index(["owner_user_id", "recorded_at", "id"], "stnu_owner_all");
        $t->foreign("person_id", "stnu_person_fk")->references("sis_persons", "id");
        $t->foreign("student_id", "stnu_student_fk")->references("sis_students", "id");
        $t->foreign("request_id", "stnu_request_fk")
            ->references("sis_transcript_requests", "id")
            ->onDelete("CASCADE");
        $t->foreign("document_id", "stnu_document_fk")
            ->references("sis_transcript_documents", "id")
            ->onDelete("CASCADE");
    }

    public function down(): void
    {
        $this->schema()->drop("sis_transcript_updates");
    }
}
