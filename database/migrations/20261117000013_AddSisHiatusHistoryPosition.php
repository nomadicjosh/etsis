<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisHiatusHistoryPosition extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "history_position",
                $this->schema()->getColumns(
                    "sis_student_hiatuses",
                    clear: true,
                ),
                true,
            )
        ) {
            $this->schema()->alter("sis_student_hiatuses", static function (
                AlterTable $t,
            ): void {
                $t->integer("history_position");
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain protected hiatus checkpoints; plan disposition explicitly.",
        );
    }
}
