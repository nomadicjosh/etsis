<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisSectionProgramEligibility extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_section_program_eligibility", clear: true)) {
            $this->schema()->create("sis_section_program_eligibility", self::define(...));
        }
        if (
            !in_array(
                "eligible_program_ids",
                $this->schema()->getColumns("sis_section_versions", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_section_versions", static function (AlterTable $t): void {
                $t->text("eligible_program_ids");
            });
        }
    }

    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("section_id", 26)->notNull();
        $t->string("program_id", 26)->notNull();
        $t->unique(["section_id", "program_id"], "sspe_section_program");
        $t->index(["program_id", "section_id"], "sspe_program_section");
        $t->foreign("section_id", "sspe_section_fk")->references("sis_sections", "id");
        $t->foreign("program_id", "sspe_program_fk")->references("sis_programs", "id");
    }

    public function down(): void
    {
        throw new LogicException("Preserve retained offering policies and verified history.");
    }
}
