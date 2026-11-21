<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisCurriculumComponentKinds extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "kind",
                $this->schema()->getColumns("sis_program_specializations", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_program_specializations", static function (
                AlterTable $t,
            ): void {
                // Earlier authoring references have no recorded role; never infer major/minor from a title.
                $t->string("kind", 24);
            });
        }
        if (
            !in_array(
                "kind_limits",
                $this->schema()->getColumns("sis_specialization_policies", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_specialization_policies", static function (
                AlterTable $t,
            ): void {
                $t->text("kind_limits");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Preserve curriculum component roles and policy facts.");
    }
}
