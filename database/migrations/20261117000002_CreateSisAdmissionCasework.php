<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdmissionCasework extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_admission_cases', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('application_id', 26)->notNull()->unique('sac_application');
            $table->string('assigned_to', 26)->set(name: 'nullable', value: true);
            $table->string('office', 191)->notNull();
            $table->string('work_status', 20)->notNull();
            $table->string('next_due_on', 10)->set(name: 'nullable', value: true);
            $table->text('encrypted_payload')->size('medium')->notNull();
            $table->integer('version')->notNull();
            $table->string('updated_by', 26)->notNull();
            $table->string('updated_at', 32)->notNull();
            $table->index(['work_status', 'next_due_on', 'id'], 'sac_follow_up');
            $table->index(['assigned_to', 'work_status', 'id'], 'sac_assignee');
            $table->foreign('application_id', 'sac_application_fk')->references('sis_applications', 'id');
        });
        $this->schema()->create('sis_admission_checklists', static function (CreateTable $table): void {
            $table->string('id', 26)->primary()->notNull();
            $table->string('name', 191)->notNull();
            $table->string('program_id', 26)->set(name: 'nullable', value: true);
            $table->integer('enabled')->notNull();
            $table->text('encrypted_definition')->size('medium')->notNull();
            $table->integer('version')->notNull();
            $table->string('updated_by', 26)->notNull();
            $table->string('updated_at', 32)->notNull();
            $table->index(['program_id', 'enabled', 'id'], 'sacl_program');
            $table->foreign('program_id', 'sacl_program_fk')->references('sis_programs', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Admissions casework requires explicit retention and disposition planning.');
    }
}
