<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisProgramCampusEligibility extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable('sis_section_program_policies', clear: true)) {
            $this->schema()->create('sis_section_program_policies', self::define(...));
        }
        $columns = $this->schema()->getColumns('sis_section_versions', clear: true);
        if (!in_array('require_program_campus', $columns, true)) {
            $this->schema()->alter('sis_section_versions', static function (AlterTable $t): void {
                $t->integer('require_program_campus');
            });
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string('id', 26)->primary()->notNull();
        $t->integer('require_program_campus')->notNull();
        $t->foreign('id', 'sspp_section_fk')->references('sis_sections', 'id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve retained section eligibility decisions and earlier unknown policy.');
    }
}
