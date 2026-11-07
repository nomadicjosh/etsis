<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionsTables extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_programs', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 80)->notNull()->unique('sis_program_code');
            $table->string('title', 191)->notNull();
        });
        $this->schema()->create('sis_terms', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('code', 22)->notNull()->unique('sis_term_code');
            $table->date('starts_on')->notNull();
            $table->date('ends_on')->notNull();
            $table->date('add_drop_ends_on')->notNull();
        });
        $this->schema()->create('sis_persons', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            // Existing user identities are retained; new SIS identities are canonical ULIDs.
            $table->string('owner_user_id', 36);
            $table->string('given_name', 191)->notNull();
            $table->string('family_name', 191)->notNull();
            $table->integer('directory_restricted')->notNull();
            $table->foreign('owner_user_id', 'sis_person_user_fk')->references('users', 'user_id');
        });
        $this->schema()->create('sis_applications', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull();
            $table->string('program_id', 26)->notNull();
            $table->string('entry_term_id', 26)->notNull();
            $table->string('status', 22)->notNull();
            $table->integer('version')->notNull();
            $table->foreign('person_id', 'sis_appl_person_fk')->references('sis_persons', 'id');
            $table->foreign('program_id', 'sis_appl_program_fk')->references('sis_programs', 'id');
            $table->foreign('entry_term_id', 'sis_appl_term_fk')->references('sis_terms', 'id');
        });
        $this->schema()->create('sis_students', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('person_id', 26)->notNull()->unique('sis_student_person');
            $table->string('entry_term_id', 26)->notNull();
            $table->integer('active')->notNull();
            $table->string('primary_program_id', 26)->notNull();
            $table->foreign('primary_program_id', 'sis_stu_program_fk')->references('sis_programs', 'id');
            $table->foreign('person_id', 'sis_stu_person_fk')->references('sis_persons', 'id');
            $table->foreign('entry_term_id', 'sis_stu_term_fk')->references('sis_terms', 'id');
        });
        $this->schema()->create('sis_student_programs', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('student_id', 26)->notNull();
            $table->unique(['student_id', 'program_id', 'entry_term_id'], 'sis_student_program_term');
            $table->string('program_id', 26)->notNull();
            $table->string('entry_term_id', 26)->notNull();
            $table->string('status', 22)->notNull();
            $table->foreign('student_id', 'sis_prog_student_fk')->references('sis_students', 'id');
            $table->foreign('program_id', 'sis_prog_program_fk')->references('sis_programs', 'id');
            $table->foreign('entry_term_id', 'sis_prog_term_fk')->references('sis_terms', 'id');
        });
    }

    public function down(): void
    {
        $tables = [
            'sis_student_programs', 'sis_students', 'sis_applications', 'sis_persons', 'sis_terms', 'sis_programs',
        ];
        foreach ($tables as $table) {
            $this->schema()->drop($table);
        }
    }
}
