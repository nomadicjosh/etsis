<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisSectionContactTime extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_section_contact_time", clear: true)) {
            $this->schema()->create("sis_section_contact_time", static function (
                CreateTable $t,
            ): void {
                $t->string("section_id", 26)->primary()->notNull();
                $t->integer("weekly_minutes");
                $t->integer("version")->notNull();
                $t->foreign("section_id")->references("sis_sections", "id");
            });
        }
        if (
            !in_array(
                "contact_hours",
                $this->schema()->getColumns("sis_section_versions", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_section_versions", static function (AlterTable $t): void {
                // SQL null means no earlier field; JSON null explicitly records unknown new contact time.
                $t->text("contact_hours");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Preserve section contact-time drafts and retained publications.");
    }
}
