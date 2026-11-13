<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleAccountRecoveries extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_moodle_account_recoveries", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("request_id", 26)->unique("moodle_recovery_request_uq")->notNull();
            $table->string("link_id", 26)->unique("moodle_recovery_link_uq")->notNull();
            $table->string("review_hash", 64)->notNull();
            $table->text("encrypted_review")->notNull();
            $table
                ->foreign("request_id", "moodle_recovery_request_fk")
                ->references("sis_moodle_account_requests", "id");
            $table
                ->foreign("link_id", "moodle_recovery_link_fk")
                ->references("sis_moodle_person_links", "id");
        });
    }

    public function down(): void
    {
        throw new LogicException("Retain original account approvals and attributed recovery decisions.");
    }
}
