<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramImportMappings extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_program_import_mappings", clear: true)) {
            $this->schema()->create("sis_program_import_mappings", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("source", 80)->notNull();
                $t->string("external_id", 80)->notNull();
                $t->string("content_hash", 64)->notNull();
                $t->string("program_id", 26)->notNull();
                $t->unique(["source", "external_id"], "spim_source_unique");
                $t->foreign("program_id", "spim_program_fk")->references("sis_programs", "id");
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Preserve imported program source identities and retry reconciliation.");
    }
}
