<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisGradebookCalculations extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_gradebook_calculations", clear: true)) {
            $this->schema()->create("sis_gradebook_calculations", static function (CreateTable $t): void {
                // One working calculation per stable section ULID. No student data or event payloads.
                $t->string("id", 26)->primary()->notNull();
                $t->string("mode", 8)->notNull();
                $t->text("weights")->notNull();
                $t->integer("revision")->notNull();
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Retain the reviewed gradebook configuration; do not silently discard it.");
    }
}
