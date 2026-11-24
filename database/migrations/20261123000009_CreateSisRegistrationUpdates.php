<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRegistrationUpdates extends Migration
{
    public function up(): void
    {
        if ($this->schema()->hasTable('sis_registration_updates', clear: true)) {
            return;
        }
        $this->schema()->create('sis_registration_updates', self::define(...));
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->string('owner_user_id', 36)->notNull();
        $t->string('person_id', 26)->notNull();
        $t->string('student_id', 26)->notNull();
        $t->string('section_id', 26)->notNull();
        $t->string('entry_id', 26)->notNull();
        $t->string('operation_id', 26)->notNull();
        $t->integer('revision')->notNull();
        $t->string('status', 12)->notNull();
        $t->string('recorded_at', 27)->notNull();
        $t->string('read_at', 27);
        $t->unique(['entry_id'], 'sru_entry_unique');
        $t->index(['owner_user_id', 'read_at', 'recorded_at', 'id'], 'sru_owner_page');
        $t->foreign('person_id', 'sru_person_fk')->references('sis_persons', 'id');
        $t->foreign('student_id', 'sru_student_fk')->references('sis_students', 'id');
        $t->foreign('section_id', 'sru_section_fk')->references('sis_sections', 'id');
        $t->foreign('entry_id', 'sru_entry_fk')->references('sis_waitlist_entries', 'id');
    }

    public function down(): void
    {
        $this->schema()->drop('sis_registration_updates');
    }
}
