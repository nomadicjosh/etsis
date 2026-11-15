<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisEmploymentDates extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_employment_dates', static function (CreateTable $t): void {
            $t->string('employee_id', 26)->primary()->notNull();
            $t->text('encrypted_dates')->size('medium')->notNull();
            $t->integer('version')->notNull();
            $t->foreign('employee_id', 'sed_employee_fk')->references('sis_employees', 'id');
        });
    }
    public function down(): void
    {
        throw new LogicException('Employment-date projections require explicit disposition and replay planning.');
    }
}
