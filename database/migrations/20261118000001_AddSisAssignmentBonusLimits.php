<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAssignmentBonusLimits extends Migration
{
    public function up(): void
    {
        if (
            !in_array("bonus_hundredths", $this->schema()->getColumns("sis_assignments", clear: true), true)
        ) {
            $this->schema()->alter("sis_assignments", static function (AlterTable $table): void {
                $table->integer("bonus_hundredths")->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Retain assignment bonus limits so recorded scores remain interpretable.");
    }
}
