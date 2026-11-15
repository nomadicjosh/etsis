<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTimesheetPositions extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_timesheet_positions', static function (CreateTable $t): void {
            $t->string('entry_id', 26)->primary()->notNull();
            $t->string('position_id', 26)->notNull();
            $t->integer('position_version')->notNull();
            $t->text('encrypted_reference')->notNull();
            $t->foreign('entry_id', 'stp_entry_fk')->references('sis_timesheets', 'id');
            $t->foreign('position_id', 'stp_position_fk')->references('sis_employee_positions', 'id');
            $t->index(['position_id', 'entry_id'], 'stp_position_entries');
        });
    }
    public function down(): void
    {
        throw new LogicException('Recorded work-position references require explicit retention planning.');
    }
}
