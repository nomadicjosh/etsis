<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisSectionEnrollmentTargets extends Migration
{
    public function up(): void
    {
        foreach (['sis_sections', 'sis_section_versions'] as $table) {
            if (!in_array('minimum_enrollment', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    // Earlier published targets remain unknown, rather than becoming invented zeroes.
                    $t->integer('minimum_enrollment');
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve the enrollment targets retained by offering publications.');
    }
}
