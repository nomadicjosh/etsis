<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisOfferingCreditPolicies extends Migration
{
    public function up(): void
    {
        foreach (['sis_courses', 'sis_course_versions'] as $name) {
            if (!in_array('credit_policy', $this->schema()->getColumns($name, clear: true), true)) {
                $this->schema()->alter($name, static fn (AlterTable $table) => $table->text('credit_policy'));
            }
        }
        foreach (
            ['sis_sections' => 'offering_credits_milli', 'sis_section_versions' => 'credits_milli'] as $name => $field
        ) {
            if (!in_array($field, $this->schema()->getColumns($name, clear: true), true)) {
                $this->schema()->alter($name, static fn (AlterTable $table) => $table->integer($field));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retained academic credit policy projections cannot be dropped.');
    }
}
