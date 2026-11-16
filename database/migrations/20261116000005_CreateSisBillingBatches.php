<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisBillingBatches extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_billing_batches', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('actor_id', 36)->notNull();
            $table->string('term_id', 26)->notNull();
            $table->string('request_hash', 64)->notNull();
            $table->text('encrypted_result')->size('medium')->notNull();
            $table->string('completed_at', 32)->notNull();
            $table->foreign('actor_id', 'sbb_actor_fk')->references('users', 'user_id');
            $table->foreign('term_id', 'sbb_term_fk')->references('sis_terms', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve completed financial retry evidence when planning an explicit rollback.');
    }
}
