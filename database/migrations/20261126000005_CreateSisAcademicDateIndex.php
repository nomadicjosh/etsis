<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAcademicDateIndex extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_academic_date_index', clear: true)) {
            $this->schema()->create('sis_academic_date_index', self::define(...));
        }
    }

    public static function define(CreateTable $table): void
    {
        $table->string('id', 26)->primary()->notNull();
        $table->string('student_id', 26)->notNull();
        $table->string('enrollment_id', 26)->notNull();
        $table->string('academic_on', 10);
        $table->string('completed_on', 10);
        $table->integer('removed')->notNull();
        $table->integer('position')->notNull();
        $table->string('head_hash', 64)->notNull();
        $table->text('sealed_state')->size('medium')->notNull();
        $table->index(['student_id', 'completed_on', 'id'], 'sadi_student_completed');
    }

    public function down(): void
    {
        throw new LogicException(
            'Reconstruct the academic date index from verified history; do not discard it on rollback.',
        );
    }
}
