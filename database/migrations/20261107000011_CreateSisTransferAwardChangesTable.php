<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTransferAwardChangesTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_transfer_award_changes", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("award_id", 26)->notNull();
            $table->string("credit_id", 26)->notNull();
            $table->string("actor_id", 36)->notNull();
            $table->integer("version")->notNull();
            $table->string("kind", 12)->notNull();
            $table->text("encrypted_content")->size("medium")->notNull();
            $table->string("created_at", 26)->notNull();
            $table->unique(["award_id", "version"], "stawc_award_version");
            $table->foreign("award_id", "stawc_award_fk")->references("sis_transfer_awards", "id");
            $table->foreign("credit_id", "stawc_credit_fk")->references("sis_academic_credits", "id");
            $table->foreign("actor_id", "stawc_actor_fk")->references("users", "user_id");
        });
    }

    public function down(): void
    {
        $this->schema()->drop("sis_transfer_award_changes");
    }
}
