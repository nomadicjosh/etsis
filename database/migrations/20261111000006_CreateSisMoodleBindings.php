<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleBindings extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_moodle_bindings", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("scope", 32)->unique("moodle_binding_scope_uq")->notNull();
            $table->string("site_fingerprint", 64)->notNull();
            $table->string("instance_fingerprint", 64)->notNull();
            $table->string("approved_by", 26)->notNull();
            $table->string("approved_at", 32)->notNull();
            $table->text("encrypted_reason")->notNull();
        });
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain reviewed installation approvals before changing integration ownership.",
        );
    }
}
