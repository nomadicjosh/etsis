<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAccountProjectionRepairs extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_account_projection_repairs', clear: true)) {
            $this->schema()->create('sis_account_projection_repairs', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('account_id', 26)->notNull();
        $t->string('completed_at', 27)->notNull();
        $t->text('sealed_result')->size('medium')->notNull();
        $t->index(['account_id', 'completed_at'], 'sapr_account_time');
        $t->foreign('account_id', 'sapr_account_fk')->references('sis_student_accounts', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve protected recovery receipts. Restore a verified backup instead.');
    }
}
