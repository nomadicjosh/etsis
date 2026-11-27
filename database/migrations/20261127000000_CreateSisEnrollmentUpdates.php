<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisEnrollmentUpdates extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_enrollment_updates', self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('owner_user_id', 36)->notNull();
        foreach (['person_id', 'student_id', 'enrollment_id', 'section_id', 'decision_id'] as $field) {
            $t->string($field, 26)->notNull();
        }
        $t->integer('revision')->notNull();
        $t->string('status', 12)->notNull();
        $t->string('recorded_at', 27)->notNull();
        $t->string('read_at', 27);
        $t->unique(['enrollment_id'], 'senu_enrollment_unique');
        $t->index(['owner_user_id', 'read_at', 'recorded_at', 'id'], 'senu_owner_page');
        $t->index(['owner_user_id', 'recorded_at', 'id'], 'senu_owner_all');
        $t->foreign('person_id', 'senu_person_fk')->references('sis_persons', 'id');
        $t->foreign('student_id', 'senu_student_fk')->references('sis_students', 'id');
        $t->foreign('section_id', 'senu_section_fk')->references('sis_sections', 'id');
        $t->foreign('enrollment_id', 'senu_stream_fk')->references('sis_registration_streams', 'id');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_enrollment_updates');
    }
}
