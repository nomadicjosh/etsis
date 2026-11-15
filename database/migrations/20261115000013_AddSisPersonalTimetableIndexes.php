<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisPersonalTimetableIndexes extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_sections', static function (AlterTable $t): void {
            $t->index(['faculty_user_id', 'term_id', 'id'], 'ss_faculty_timetable');
        });
        $this->schema()->alter('sis_enrollments', static function (AlterTable $t): void {
            $t->index(['student_id', 'term_id', 'status', 'section_id'], 'se_student_timetable');
        });
        $this->schema()->alter('sis_students', static function (AlterTable $t): void {
            $t->index(['person_id', 'id'], 'sst_person_timetable');
        });
        $this->schema()->alter('sis_persons', static function (AlterTable $t): void {
            $t->index(['owner_user_id', 'id'], 'sp_owner_timetable');
        });
    }

    public function down(): void
    {
        // InnoDB may reuse these indexes for existing foreign keys and remove its earlier implicit indexes.
        throw new LogicException('Personal timetable index removal requires explicit foreign-key/index planning.');
    }
}
