<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAdmissionChecklistPolicy extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns(
            "sis_admission_policy",
            clear: true,
        );
        if (!in_array("require_checklist", $columns, true)) {
            $this->schema()->alter("sis_admission_policy", static function (
                AlterTable $t,
            ): void {
                $t->integer("require_checklist")->notNull()->defaultValue(0);
            });
        }
        if (!in_array("checklist_revision", $columns, true)) {
            $this->schema()->alter("sis_admission_policy", static function (
                AlterTable $t,
            ): void {
                $t->integer("checklist_revision")->notNull()->defaultValue(0);
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain institutional checklist policy and decision evidence; plan disposition explicitly.",
        );
    }
}
