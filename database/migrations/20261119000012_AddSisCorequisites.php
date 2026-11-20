<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisCorequisites extends Migration
{
    public function up(): void
    {
        foreach (
            ['sis_course_prerequisite_groups' => 'corequisites_json',
            'sis_course_versions' => 'corequisites'] as $table => $column
        ) {
            if (!in_array($column, $this->schema()->getColumns($table, clear: true), true)) {
                $this->schema()->alter($table, static function (AlterTable $t) use ($column): void {
                    $t->text($column);
                });
            }
        }
        $columns = $this->schema()->getColumns('sis_registration_policy', clear: true);
        foreach (['coreq_prior', 'coreq_drop', 'coreq_revision'] as $column) {
            if (in_array($column, $columns, true)) {
                continue;
            }
            $this->schema()->alter('sis_registration_policy', static function (AlterTable $t) use ($column): void {
                if ($column === 'coreq_drop') {
                    $t->string($column, 8)->notNull()->defaultValue('block');
                } else {
                    $t->integer($column)->notNull()->defaultValue($column === 'coreq_prior' ? 1 : 0);
                }
            });
        }
    }
    public function down(): void
    {
        throw new LogicException('Preserve co-requisite definitions and accepted policy references.');
    }
}
