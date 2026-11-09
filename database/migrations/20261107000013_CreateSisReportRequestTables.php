<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisReportRequestTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_report_requests", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("requested_by", 36)->notNull();
            $table->string("assigned_to", 36);
            $table->string("status", 24)->notNull();
            $table->integer("version")->notNull();
            $table->string("due_on", 10);
            $table->string("report_id", 26);
            $table->string("created_at", 26)->notNull();
            $table->string("updated_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->index(["requested_by", "status", "id"], "srr_requester_page");
            $table->index(["status", "id"], "srr_queue_page");
            $table->index(["requested_by", "id"], "srr_owner_all_page");
            $table->foreign("requested_by", "srr_requester_fk")->references("users", "user_id");
            $table->foreign("assigned_to", "srr_assignee_fk")->references("users", "user_id");
            $table->foreign("report_id", "srr_report_fk")->references("sis_reports", "id");
        });
        $this->schema()->create("sis_report_request_actions", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("request_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->integer("version")->notNull();
            $table->string("request_hash", 64)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->unique(["request_id", "version"], "srra_version_page");
            $table->foreign("request_id", "srra_request_fk")->references("sis_report_requests", "id");
            $table->foreign("actor_id", "srra_actor_fk")->references("users", "user_id");
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_report_request_actions");
        $this->schema()->drop("sis_report_requests");
    }
}
