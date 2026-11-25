<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvisingUpdates extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_advising_updates', clear: true)) {
            $this->schema()->create('sis_advising_updates', self::define(...));
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('owner_user_id', 36)->notNull();
        foreach (['person_id', 'student_id', 'pursuit_id', 'checkin_id'] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer('revision')->notNull();
        $t->string('status', 12)->notNull();
        $t->string('recorded_at', 27)->notNull();
        $t->string('read_at', 27);
        $t->unique(['checkin_id'], 'sadu_checkin_unique');
        $t->index(['owner_user_id', 'read_at', 'recorded_at', 'id'], 'sadu_owner_page');
        $t->foreign('person_id', 'sadu_person_fk')->references('sis_persons', 'id');
        $t->foreign('student_id', 'sadu_student_fk')->references('sis_students', 'id');
        $t->foreign('pursuit_id', 'sadu_pursuit_fk')->references('sis_student_programs', 'id');
        $t->foreign('checkin_id', 'sadu_checkin_fk')->references('sis_advising_checkins', 'id');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_advising_updates');
    }
}
