<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentSpecializationRequirements extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_student_specialization_requirements', clear: true)) {
            $this->schema()->create('sis_student_specialization_requirements', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('assignment_id', 26)->notNull();
                $t->string('specialization_id', 26)->notNull();
                $t->text('reference_json')->notNull();
                $t->unique(['assignment_id', 'specialization_id'], 'ssrq_assignment_choice');
                $t->foreign('assignment_id', 'ssrq_assignment_fk')->references('sis_student_programs', 'id');
                $t->foreign('specialization_id', 'ssrq_choice_fk')->references('sis_program_specializations', 'id');
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            'Preserve specialization requirement selections and rebuild only from verified history.',
        );
    }
}
