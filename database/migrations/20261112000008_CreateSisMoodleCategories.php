<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleCategories extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_moodle_category_settings", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("binding_id", 26)->unique("moodle_category_setting_scope_uq")->notNull();
            $t->string("source", 12)->notNull();
            $t->integer("version")->notNull();
            $t->string("updated_by", 26)->notNull();
            $t->string("updated_at", 32)->notNull();
            $t->foreign("binding_id", "moodle_category_setting_binding_fk")->references(
                "sis_moodle_bindings",
                "id",
            );
        });
        $this->schema()->create("sis_moodle_categories", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("binding_id", 26)->notNull();
            $t->string("source", 12)->notNull();
            $t->string("reference_id", 26)->notNull();
            $t->string("external_id", 20);
            $t->boolean("is_owned")->notNull()->defaultValue(false);
            $t->integer("version")->notNull()->defaultValue(0);
            $t->integer("lease_until")->notNull()->defaultValue(0);
            $t->integer("attempts")->notNull()->defaultValue(0);
            $t->integer("checked_at")->notNull()->defaultValue(0);
            $t->string("source_code", 80);
            $t->string("source_title", 191);
            $t->string("source_hash", 64);
            $t->string("issue_code", 40)->notNull()->defaultValue("none");
            $t->text("encrypted_link");
            $t->unique(["binding_id", "source", "reference_id"], "moodle_category_source_uq");
            $t->index(["binding_id", "source", "lease_until"], "moodle_category_work_idx");
            $t->foreign("binding_id", "moodle_category_binding_fk")->references("sis_moodle_bindings", "id");
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain category policy decisions and original Moodle mappings.");
    }
}
