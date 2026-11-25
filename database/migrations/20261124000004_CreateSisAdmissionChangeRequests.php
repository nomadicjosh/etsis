<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionChangeRequests extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_admission_change_requests', clear: true)) {
            $this->schema()->create('sis_admission_change_requests', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('application_id', 26)->notNull();
        $t->string('person_id', 26)->notNull();
        $t->string('owner_user_id', 26)->notNull();
        $t->string('area', 20)->notNull();
        $t->string('status', 12)->notNull();
        $t->integer('version')->notNull();
        $t->text('sealed_data')->size('medium')->notNull();
        $t->index(['application_id', 'status', 'id'], 'sacr_app_page');
        $t->index(['application_id', 'area', 'status'], 'sacr_active_area');
        $t->foreign('application_id', 'sacr_app_fk')->references('sis_applications', 'id');
        $t->foreign('person_id', 'sacr_person_fk')->references('sis_persons', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve private application follow-ups until approved retention/disposition.');
    }
}
