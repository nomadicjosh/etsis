<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisEmployeePositions extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_employee_positions', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('employee_id', 26)->notNull();
            $t->text('encrypted_position')->size('medium')->notNull();
            $t->integer('version')->notNull();
            $t->foreign('employee_id', 'sep_employee_fk')->references('sis_employees', 'id');
            $t->index(['employee_id', 'id'], 'sep_employee_page');
        });
    }
    public function down(): void
    {
        throw new LogicException('Position projections require explicit disposition and replay planning.');
    }
}
