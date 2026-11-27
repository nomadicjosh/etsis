<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisCourseAuditing extends Migration
{
    public function up(): void
    {
        foreach (['sis_sections', 'sis_section_versions'] as $table) {
            if (!in_array('auditing', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->string('auditing', 256);
                });
            }
        }
        foreach (['sis_enrollments', 'sis_academic_credits'] as $table) {
            if (!in_array('basis', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t): void {
                    $t->string('basis', 16)->notNull()->defaultValue('standard');
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve recorded enrollment bases and published auditing rules.');
    }
}
