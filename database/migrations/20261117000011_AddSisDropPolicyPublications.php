<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisDropPolicyPublications extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns(
            "sis_term_drop_policies",
            clear: true,
        );
        if (!in_array("publication_id", $columns, true)) {
            $this->schema()->alter("sis_term_drop_policies", static function (
                AlterTable $t,
            ): void {
                $t->string("publication_id", 26);
            });
        }
        if (!in_array("calendar_snapshot", $columns, true)) {
            $this->schema()->alter("sis_term_drop_policies", static function (
                AlterTable $t,
            ): void {
                $t->text("calendar_snapshot");
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain published policy references; plan disposition explicitly.",
        );
    }
}
