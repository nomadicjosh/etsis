<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisGradingScales extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_grading_scales", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("scope_key", 26)->notNull()->unique("sgs_scope");
            $t->string("program_id", 26);
            $t->string("version_id", 26)->notNull();
            $t->integer("position")->notNull();
            $t->text("definition_json")->size("medium");
            $t->foreign("program_id", "spgs_program_fk")->references("sis_programs", "id");
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_grading_scales");
    }
}
