<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTransferCreditStreams extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_transfer_credit_streams", true)) {
            $this->schema()->create("sis_transfer_credit_streams", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("credit_id", 26)->notNull()->unique("stcs_credit");
                $t->index(["student_id", "id"], "stcs_student");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Retained transfer stream routing must not be discarded.");
    }
}
