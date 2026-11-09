<?php

declare(strict_types=1);

use Qubus\Exception\Exception;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisProgramStatusStreams extends Migration
{
    /**
     * @throws Exception
     */
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_program_status_streams", true)) {
            $this->schema()->create("sis_program_status_streams", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->integer("stream_version")->notNull();
                $t->string("last_decision_id", 26)->notNull();
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Retained program history checkpoints must not be discarded.");
    }
}
