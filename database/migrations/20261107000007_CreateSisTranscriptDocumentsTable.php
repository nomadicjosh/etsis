<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTranscriptDocumentsTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_transcript_documents", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("request_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->integer("prepared_version")->notNull();
            $table->string("created_at", 26)->notNull();
            $table->string("pdf_hash", 64)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->unique(["request_id", "prepared_version"], "std_request_revision");
            $table->foreign("request_id", "std_request_fk")->references("sis_transcript_requests", "id");
            $table->foreign("actor_id", "std_actor_fk")->references("users", "user_id");
        });
        $this->schema()->create("sis_transcript_deliveries", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("document_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->text("encrypted_content")->notNull();
            $table->unique(["document_id"], "std_delivery_unique");
            $table->foreign("document_id", "std_delivery_document_fk")->references("sis_transcript_documents", "id");
            $table->foreign("actor_id", "std_delivery_actor_fk")->references("users", "user_id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_transcript_deliveries");
        $this->schema()->drop("sis_transcript_documents");
    }
}
