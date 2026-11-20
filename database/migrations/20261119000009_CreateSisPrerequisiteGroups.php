<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPrerequisiteGroups extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_course_prerequisite_groups', clear: true)) {
            $this->schema()->create('sis_course_prerequisite_groups', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->text('groups_json')->notNull();
                $t->foreign('id', 'scpg_course_fk')->references('sis_courses', 'id');
            });
        }
        if (!in_array('prerequisite_groups', $this->schema()->getColumns('sis_course_versions', clear: true), true)) {
            $this->schema()->alter('sis_course_versions', static function (AlterTable $t): void {
                // NULL preserves the original all-required contract of earlier publications.
                $t->text('prerequisite_groups');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve the prerequisite choices used by course publications and registrations.');
    }
}
