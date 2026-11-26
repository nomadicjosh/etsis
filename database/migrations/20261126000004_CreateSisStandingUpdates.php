<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStandingUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_standing_updates', self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('owner_user_id', 36)->notNull();
        foreach (['person_id', 'student_id', 'pursuit_id', 'program_id', 'operation_id'] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer('revision')->notNull();
        $t->string('status', 12)->notNull();
        $t->string('recorded_at', 27)->notNull();
        $t->string('read_at', 27);
        $t->unique(['pursuit_id'], 'ssnu_pursuit_unique');
        $t->index(['owner_user_id', 'read_at', 'recorded_at', 'id'], 'ssnu_owner_page');
        $t->index(['owner_user_id', 'recorded_at', 'id'], 'ssnu_owner_all');
        $t->foreign('person_id', 'ssnu_person_fk')->references('sis_persons', 'id');
        $t->foreign('student_id', 'ssnu_student_fk')->references('sis_students', 'id');
        $t->foreign('program_id', 'ssnu_program_fk')->references('sis_programs', 'id');
        $t->foreign('pursuit_id', 'ssnu_pursuit_fk')->references('sis_student_programs', 'id')->onDelete('CASCADE');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_standing_updates');
    }
}
