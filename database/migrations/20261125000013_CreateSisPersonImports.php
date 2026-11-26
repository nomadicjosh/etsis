<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonImports extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_person_imports", static function (
            CreateTable $t,
        ): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("actor_id", 36)->notNull();
            $t->foreign("actor_id", "spi_actor_fk")->references(
                "users",
                "user_id",
            );
            $t->string("proof_hash", 64)->notNull();
            $t->text("encrypted_receipt")->notNull();
            $t->string("created_at", 26)->notNull();
        });
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve accepted people-import receipts and their identity links.",
        );
    }
}
