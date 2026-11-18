<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisScoreExcusals extends Migration
{
    public function up(): void
    {
        if (!in_array("excused", $this->schema()->getColumns("sis_scores", clear: true), true)) {
            $this->schema()->alter("sis_scores", static function (AlterTable $table): void {
                $table->integer("excused")->notNull()->defaultValue(0);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain score excusals so recorded marks and their current inclusion remain interpretable.",
        );
    }
}
