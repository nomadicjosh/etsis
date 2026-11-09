<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPrimaryProgramStreams extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_primary_program_streams", true)) {
            $this->schema()->create("sis_primary_program_streams", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("assignment_id", 26)->notNull();
                $t->integer("stream_version")->notNull();
                $t->string("last_decision_id", 26)->notNull();
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Retained primary selection checkpoints must not be discarded.");
    }
}
