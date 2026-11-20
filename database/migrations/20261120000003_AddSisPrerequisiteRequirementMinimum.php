<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteRequirementMinimum extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            if (!in_array('requirement_minimum', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    // Null preserves the original all-required meaning without rewriting retained facts.
                    $t->integer('requirement_minimum');
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve the prerequisite requirements used in publications and accepted decisions.');
    }
}
