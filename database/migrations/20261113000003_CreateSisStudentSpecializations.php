<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentSpecializations extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_student_specializations', clear: true)) {
            $this->schema()->create('sis_student_specializations', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('student_id', 26)->notNull();
                $t->string('program_id', 26)->notNull();
                $t->string('academic_year_id', 26)->notNull();
                $t->string('program_version_id', 26)->notNull();
                $t->integer('cohort_binding_revision')->notNull();
                $t->string('decision_id', 26)->notNull();
                $t->string('previous_decision_id', 26);
                $t->integer('stream_version')->notNull();
                $t->text('selected_json')->notNull();
                $t->index(['student_id', 'id'], 'sspec_student_page');
                $t->foreign('id', 'sspec_assignment_fk')->references('sis_student_programs', 'id');
                $t->foreign('student_id', 'sspec_student_fk')->references('sis_students', 'id');
                $t->foreign('program_id', 'sspec_program_fk')->references('sis_programs', 'id');
                $t->foreign('academic_year_id', 'sspec_year_fk')->references('sis_academic_years', 'id');
                $t->foreign('program_version_id', 'sspec_catalog_fk')->references('sis_program_catalogs', 'id');
            });
        }
    }
    public function down(): void
    {
        throw new LogicException('Preserve student selection projections; rebuild only from verified history.');
    }
}
