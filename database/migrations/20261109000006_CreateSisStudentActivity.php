<?php

declare(strict_types=1);

use Qubus\Exception\Exception;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentActivity extends Migration
{
    /**
     * @throws Exception
     */
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_student_activity", true)) {
            $this->schema()->create("sis_student_activity", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->integer("stream_version")->notNull();
                $t->string("last_decision_id", 26)->notNull();
                $t->string("current_decision_id", 26)->notNull();
                $t->string("effective_on", 10)->notNull();
            });
        }
    }
    public function down(): void
    {
        throw new LogicException("Retained activity projection checkpoints must not be discarded.");
    }
}
