<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramSpecializations extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_program_specializations', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('program_id', 26)->notNull();
            $t->string('code', 80)->notNull();
            $t->string('title', 191)->notNull();
            $t->text('description')->size('medium');
            $t->boolean('active')->notNull()->defaultValue(true);
            $t->integer('revision')->notNull();
            $t->unique(['program_id', 'code'], 'sps_program_code');
            $t->index(['program_id', 'id'], 'sps_program_page');
            $t->foreign('program_id', 'sps_program_fk')->references('sis_programs', 'id');
        });
    }

    public function down(): void
    {
        throw new LogicException(
            'Preserve specialization definitions; restore a verified backup for rollback.',
        );
    }
}
