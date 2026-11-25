<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteCompletionWindows extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            if (!in_array('completion_windows_json', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->text('completion_windows_json');
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Preserve completion windows in retained course publications and registration decisions.'
        );
    }
}
