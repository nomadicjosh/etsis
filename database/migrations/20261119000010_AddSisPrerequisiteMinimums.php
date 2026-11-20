<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteMinimums extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            $field = $table === 'sis_course_versions' ? 'prerequisite_minimums' : 'minimums_json';
            if (!in_array($field, $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($field): void {
                    // Absence preserves the original one-course meaning; do not rewrite earlier publications.
                    $t->text($field);
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Retain the course minima used by published prerequisite rules and accepted decisions.'
        );
    }
}
