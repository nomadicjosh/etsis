<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAttendanceVersions extends Migration
{
    public function up(): void
    {
        if (!in_array("version", $this->schema()->getColumns("sis_attendance", clear: true), true)) {
            $this->schema()->alter("sis_attendance", static function (AlterTable $table): void {
                $table->integer("version")->notNull()->defaultValue(1);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            "Retain attendance versions so stale corrections cannot overwrite later marks.",
        );
    }
}
