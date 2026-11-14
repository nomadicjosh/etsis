<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

/** A specialization/year authoring scope, using the existing requirements publication aggregate. */
final class CreateSisSpecializationAuditTemplates extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_specialization_audit_templates', clear: true)) {
            $this->schema()->create('sis_specialization_audit_templates', static function (CreateTable $t): void {
                $t->string('id', 26)->primary()->notNull();
                $t->string('program_id', 26)->notNull();
                $t->string('specialization_id', 26)->notNull();
                $t->string('academic_year_id', 26)->notNull();
                $t->integer('revision')->notNull();
                $t->text('draft_json')->size('medium')->notNull();
                $t->string('published_version_id', 26);
                $t->integer('publication_position')->notNull();
                $t->unique(['program_id', 'specialization_id', 'academic_year_id'], 'ssat_scope_unique');
                $t->index(['program_id', 'specialization_id', 'id'], 'ssat_scope_page');
                $t->foreign('program_id', 'ssat_program_fk')->references('sis_programs', 'id');
                $t->foreign('specialization_id', 'ssat_specialization_fk')
                    ->references('sis_program_specializations', 'id');
                $t->foreign('academic_year_id', 'ssat_year_fk')->references('sis_academic_years', 'id');
            });
        }
    }
    public function down(): void
    {
        throw new LogicException('Preserve published specialization references and their authoring scope.');
    }
}
