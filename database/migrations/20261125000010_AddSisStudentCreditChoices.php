<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisStudentCreditChoices extends Migration
{
    public function up(): void
    {
        foreach (['sis_sections', 'sis_section_versions'] as $table) {
            if (!in_array('student_selectable_credits', $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($table): void {
                    self::define($t, $table === 'sis_sections');
                });
            }
        }
        if (
            !in_array(
                'selected_credits_milli',
                $this->schema()->getColumns('sis_enrollment_section_versions', clear: true),
                true
            )
        ) {
            $this->schema()->alter('sis_enrollment_section_versions', static function (AlterTable $table): void {
                $table->integer('selected_credits_milli');
            });
        }
        if (
            !in_array(
                'encrypted_credit_choice',
                $this->schema()->getColumns('sis_waitlist_entries', clear: true),
                true
            )
        ) {
            $this->schema()->alter('sis_waitlist_entries', static function (AlterTable $table): void {
                $table->text('encrypted_credit_choice');
            });
        }
    }

    public static function define(AlterTable $table, bool $draft): void
    {
        $column = $table->integer('student_selectable_credits');
        if ($draft) {
            $column->notNull()->defaultValue(0);
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve original offering modes and accepted student credit selections.');
    }
}
