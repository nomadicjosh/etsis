<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRegistrationBatchesTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_registration_batches', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('actor_id', 36)->notNull();
            $table->string('section_id', 26)->notNull();
            $table->string('request_hash', 64)->notNull();
            $table->text('result_json')->notNull();
            $table->string('completed_at', 32)->notNull();
            $table->foreign('actor_id', 'srb_actor_fk')->references('users', 'user_id');
            $table->foreign('section_id', 'srb_section_fk')->references('sis_sections', 'id');
        });
    }

    public function down(): void
    {
        $this->schema()->drop('sis_registration_batches');
    }
}
