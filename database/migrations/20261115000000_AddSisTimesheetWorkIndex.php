<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisTimesheetWorkIndex extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_timesheets', static function (AlterTable $table): void {
            $table->index(['employee_id', 'starts_at', 'id'], 'sts_employee_work');
        });
    }

    public function down(): void
    {
        $this->schema()->alter('sis_timesheets', static function (AlterTable $table): void {
            $table->dropIndex('sts_employee_work');
        });
    }
}
