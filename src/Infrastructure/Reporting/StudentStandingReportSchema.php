<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Current accepted dated periods only. Protected decisions and staff evidence are not report sources. */
final class StudentStandingReportSchema
{
    public static function dataset(): array
    {
        $join = static fn(
            string $table,
            string $parent,
            string $left,
            string $right,
            string $label,
        ): array => [
            "table" => $table,
            "parent" => $parent,
            "left" => $left,
            "right" => $right,
            "label" => $label,
            "permissions" => [],
        ];
        $field = static fn(string $label, string $type, string $relation, string $column): array => [
            "label" => $label,
            "type" => $type,
            "relation" => $relation,
            "column" => $column,
            "permissions" => [],
        ];
        return [
            "student_population" => true,
            "table" => "sis_student_standing",
            "root" => "standing",
            "label" => "Academic standing periods",
            "description" =>
                "One row per recorded standing period under a student program pursuit. " .
                "Filter effective dates explicitly. Program and person names are current labels; " .
                "standing names are retained.",
            "permissions" => ["sis:student:read", "sis:standing:manage"],
            "relationships" => [
                "student" => $join("sis_students", "standing", "student_id", "id", "Student records"),
                "person" => $join("sis_persons", "student", "person_id", "id", "Student names"),
                "identity" => $join(
                    "sis_person_identifiers",
                    "person",
                    "id",
                    "person_id",
                    "Institutional IDs",
                ),
                "pursuit" => $join(
                    "sis_student_programs",
                    "standing",
                    "pursuit_id",
                    "id",
                    "Program pursuits",
                ),
                "program" => $join("sis_programs", "pursuit", "program_id", "id", "Academic programs"),
            ],
            "fields" => [
                "standing_id" => $field("Standing period reference", "string", "standing", "id"),
                "standing_code" => $field("Standing code", "string", "standing", "code"),
                "standing_title" => $field("Standing name", "string", "standing", "title"),
                "effective_from" => $field("Effective from", "date", "standing", "starts_on"),
                "effective_through" => $field("Effective through", "date", "standing", "ends_on"),
                "pursuit_id" => $field("Program pursuit reference", "string", "standing", "pursuit_id"),
                "pursuit_status" => $field("Program pursuit status", "string", "pursuit", "status"),
                "student_id" => $field("Student reference", "string", "student", "id"),
                "institutional_id" => $field("Institutional ID", "string", "identity", "institutional_id"),
                "full_name" => $field("Student full name", "string", "person", "given_name") + [
                    "expression" => "full_name",
                ],
                "given_name" => $field("Given name", "string", "person", "given_name"),
                "family_name" => $field("Family name", "string", "person", "family_name"),
                "program_code" => $field("Program code", "string", "program", "code"),
                "program_title" => $field("Program title", "string", "program", "title"),
            ],
        ];
    }
}
