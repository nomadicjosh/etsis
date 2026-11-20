<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteGradePoints extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            $field = $table === 'sis_course_versions' ? 'prerequisite_grade_points' : 'grade_points_json';
            if (!in_array($field, $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($field): void {
                    // Absence preserves the original passing-grade meaning; do not rewrite earlier publications.
                    $t->text($field);
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Retain the grade minima used by published prerequisite rules and accepted decisions.'
        );
    }
}
