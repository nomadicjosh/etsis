<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramDepartments extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_program_departments', clear: true)) {
            $this->schema()->create('sis_program_departments', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('program_id', 26)->notNull();
                $t->string('department_id', 26)->notNull();
                $t->unique(['program_id', 'department_id'], 'spd_owner_unique');
                $t->foreign('program_id', 'spd_program_fk')->references('sis_programs', 'id');
                $t->foreign('department_id', 'spd_department_fk')->references('sis_catalog_classifications', 'id');
            });
        }
        if (!$this->schema()->hasTable('sis_program_catalog_ownership', clear: true)) {
            $this->schema()->create('sis_program_catalog_ownership', static function (CreateTable $t): void {
                $t->string('id', 26)->notNull()->primary();
                $t->string('program_id', 26)->notNull();
                $t->integer('stream_version')->notNull();
                $t->text('snapshot_json')->notNull();
                $t->string('snapshot_hash', 64)->notNull();
                $t->foreign('id', 'spco_catalog_fk')->references('sis_program_catalog_details', 'id');
                $t->foreign('program_id', 'spco_program_fk')->references('sis_programs', 'id');
            });
        }
        $columns = $this->schema()->getColumns('sis_program_catalog_details', clear: true);
        if (!in_array('department_ownership_recorded', $columns, true)) {
            $this->schema()->alter('sis_program_catalog_details', static function (AlterTable $t): void {
                $t->integer('department_ownership_recorded')->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve program ownership and retained catalog definitions.');
    }
}
