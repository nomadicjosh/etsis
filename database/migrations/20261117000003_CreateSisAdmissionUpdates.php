<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_admission_updates', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('application_id', 26)->notNull();
            $table->string('owner_user_id', 26)->notNull();
            $table->integer('revision')->notNull();
            $table->integer('read_revision')->notNull();
            $table->string('updated_at', 32)->notNull();
            $table->unique(['application_id', 'owner_user_id'], 'sau_application_owner');
            $table->index(['owner_user_id', 'updated_at', 'id'], 'sau_owner_page');
            $table->foreign('application_id', 'sau_application_fk')->references('sis_applications', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Admission update references require explicit disposition planning.');
    }
}
