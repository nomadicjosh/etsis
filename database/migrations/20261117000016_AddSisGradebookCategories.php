<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisGradebookCategories extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "categories",
                $this->schema()->getColumns("sis_gradebook_calculations", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_gradebook_calculations", static function (AlterTable $t): void {
                $t->text("categories");
            });
        }
    }

    public function down(): void
    {
        throw new LogicException("Retain gradebook setup; do not silently discard category weights.");
    }
}
