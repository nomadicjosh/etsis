<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisOfferingTemplateTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_offering_templates", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("name", 120)->notNull();
            $table->boolean("active")->notNull();
            $table->integer("version")->notNull();
            $table->integer("section_count")->notNull();
            $table->string("created_by", 36)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->string("updated_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->index(["active", "id"], "sot_active_page");
            $table->foreign("created_by", "sot_creator_fk")->references("users", "user_id");
        });
        $this->schema()->create("sis_offering_template_changes", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("template_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->integer("version")->notNull();
            $table->string("request_hash", 64)->notNull();
            $table->string("created_at", 26)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->unique(["template_id", "version"], "sotc_version_page");
            $table->foreign("template_id", "sotc_template_fk")->references("sis_offering_templates", "id");
            $table->foreign("actor_id", "sotc_actor_fk")->references("users", "user_id");
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_offering_template_changes");
        $this->schema()->drop("sis_offering_templates");
    }
}
