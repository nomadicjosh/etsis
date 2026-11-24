<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPrerequisiteCreditOrigin extends Migration
{
    public function up(): void
    {
        foreach (['sis_course_prerequisite_groups', 'sis_course_versions'] as $table) {
            if (!in_array('prerequisite_credit_origin', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->string('prerequisite_credit_origin', 13);
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve coursework-origin policies in retained publications and registrations.');
    }
}
