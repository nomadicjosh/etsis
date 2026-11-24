<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisContinuingEducationUnits extends Migration
{
    public function up(): void
    {
        foreach (['sis_courses', 'sis_course_versions', 'sis_sections', 'sis_section_versions'] as $name) {
            if (!in_array('continuing_education', $this->schema()->getColumns($name, clear: true), true)) {
                $this->schema()->alter($name, static fn (AlterTable $table) => $table->text('continuing_education'));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retained CEU definitions cannot be dropped.');
    }
}
