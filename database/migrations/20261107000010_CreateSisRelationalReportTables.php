<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRelationalReportTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_report_queries", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->integer("version")->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->foreign("id", "srq_report_fk")->references("sis_reports", "id");
        });
        $this->schema()->create("sis_report_query_changes", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("report_id", 26)->notNull();
            $table->integer("version")->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->unique(["report_id", "version"], "srqc_report_version");
            $table->foreign("report_id", "srqc_report_fk")->references("sis_reports", "id");
            $table->foreign("actor_id", "srqc_actor_fk")->references("users", "user_id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_report_query_changes");
        $this->schema()->drop("sis_report_queries");
    }
}
