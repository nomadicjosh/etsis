<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisCourseNumbers extends Migration
{
    public function up(): void
    {
        $definitions = [
            "sis_course_descriptions" => [
                "course_number",
                "published_course_number",
                "published_number_recorded",
            ],
            "sis_course_versions" => ["course_number", "number_recorded"],
        ];
        foreach ($definitions as $table => $fields) {
            foreach ($fields as $field) {
                if (in_array($field, $this->schema()->getColumns($table, clear: true), true)) {
                    continue;
                }
                $this->schema()->alter($table, static function (AlterTable $t) use ($field): void {
                    if (str_ends_with($field, "recorded")) {
                        $t->boolean($field)->defaultValue(false)->notNull();
                    } else {
                        $t->string($field, 32);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException("Preserve published course numbers and their earlier unknowns.");
    }
}
