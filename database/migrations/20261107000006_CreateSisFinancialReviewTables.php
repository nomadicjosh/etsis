<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisFinancialReviewTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_financial_reviews", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("student_id", 26)->notNull();
            $table->string("hiatus_id", 26)->notNull();
            $table->unique(["hiatus_id"], "sfr_hiatus_unique");
            $table->string("requested_by", 36)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->string("status", 24)->notNull();
            $table->string("assigned_to", 36);
            $table->integer("version")->notNull();
            $table->index(["status", "id"], "sfr_status_page");
            $table->foreign("student_id", "sfr_student_fk")->references("sis_students", "id");
            $table->foreign("hiatus_id", "sfr_hiatus_fk")->references("sis_student_hiatuses", "id");
            $table->foreign("requested_by", "sfr_requester_fk")->references("users", "user_id");
            $table->foreign("assigned_to", "sfr_assignee_fk")->references("users", "user_id");
        });
        $this->schema()->create("sis_financial_review_actions", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("review_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->index(["review_id", "id"], "sfra_history_page");
            $table->foreign("review_id", "sfra_review_fk")->references("sis_financial_reviews", "id");
            $table->foreign("actor_id", "sfra_actor_fk")->references("users", "user_id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_financial_review_actions");
        $this->schema()->drop("sis_financial_reviews");
    }
}
