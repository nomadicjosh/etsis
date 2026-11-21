<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProfileUpdateRequests extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_profile_update_requests', clear: true)) {
            $this->schema()->create('sis_profile_update_requests', static function (CreateTable $table): void {
                $table->string('id', 26)->primary()->notNull();
                $table->string('person_id', 26)->notNull();
                $table->string('owner_user_id', 26)->notNull();
                $table->string('area', 20)->notNull();
                $table->string('status', 12)->notNull();
                $table->integer('version')->notNull();
                $table->text('sealed_data')->size('medium')->notNull();
                $table->index(['person_id', 'status', 'id'], 'spur_person_page');
                $table->index(['person_id', 'area', 'status'], 'spur_active_area');
                $table->foreign('person_id', 'spur_person_fk')->references('sis_persons', 'id');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve private profile-request records until approved retention/disposition.');
    }
}
