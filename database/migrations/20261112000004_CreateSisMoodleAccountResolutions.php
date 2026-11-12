<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisMoodleAccountResolutions extends Migration
{
    public function up(): void
    {
        $this->schema()->alter("sis_moodle_account_requests", static function (AlterTable $table): void {
            $table->string("resolution_hash", 64);
            $table->text("encrypted_resolution");
        });
    }

    public function down(): void
    {
        throw new LogicException("Retain attributed account-access resolutions.");
    }
}
