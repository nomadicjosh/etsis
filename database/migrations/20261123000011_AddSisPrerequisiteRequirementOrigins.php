<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteRequirementOrigins extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            if (!in_array('requirement_origins_json', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->text('requirement_origins_json');
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve requirement coursework sources in retained publications and decisions.');
    }
}
