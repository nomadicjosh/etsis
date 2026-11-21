<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Current working marks, never an inferred class census or authoritative attendance certification. */
final class AttendanceReportSchema
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
        $field = static fn(
            string $label,
            string $type,
            string $relation,
            string $column,
        ): array => [
            "label" => $label,
            "type" => $type,
            "relation" => $relation,
            "column" => $column,
            "permissions" => [],
        ];
        return [
            "id" => "attendance",
            "table" => "sis_attendance",
            "root" => "attendance",
            "source_scope" => "active_attendance",
            "student_population" => true,
            "label" => "Recorded attendance",
            "description" =>
                "One current working mark per student, section and class date, for active registrations only. " .
                "Unmarked dates have no recorded status. Names and course/section/term labels are current. " .
                "This is not a reconstructed past roster, attendance rate or formal certification.",
            "permissions" => ["sis:student:read", "sis:gradebook:manage"],
            "relationships" => [
                "student" => $join(
                    "sis_students",
                    "attendance",
                    "student_id",
                    "id",
                    "Student records",
                ),
                "person" => $join("sis_persons", "student", "person_id", "id", "Student names"),
                "identity" => $join(
                    "sis_person_identifiers",
                    "person",
                    "id",
                    "person_id",
                    "Institutional IDs",
                ),
                "section" => $join(
                    "sis_sections",
                    "attendance",
                    "section_id",
                    "id",
                    "Current sections",
                ),
                "course" => $join("sis_courses", "section", "course_id", "id", "Current courses"),
                "term" => $join("sis_terms", "section", "term_id", "id", "Academic terms"),
            ],
            "fields" => [
                "attendance_id" => $field(
                    "Attendance mark reference",
                    "string",
                    "attendance",
                    "id",
                ),
                "held_on" => $field("Class date", "date", "attendance", "held_on"),
                "attendance_status" => $field(
                    "Recorded attendance",
                    "string",
                    "attendance",
                    "status",
                ) + [
                    "choices" => [
                        "present" => "Present",
                        "absent" => "Absent",
                        "late" => "Late",
                        "excused" => "Excused",
                    ],
                ],
                "student_id" => $field("Student reference", "string", "student", "id"),
                "institutional_id" => $field(
                    "Institutional ID",
                    "string",
                    "identity",
                    "institutional_id",
                ),
                "full_name" => $field("Student full name", "string", "person", "given_name") + [
                    "expression" => "full_name",
                ],
                "active" => $field("Active student", "boolean", "student", "active"),
                "section_code" => $field("Current section code", "string", "section", "code"),
                "course_code" => $field("Current course code", "string", "course", "code"),
                "course_title" => $field("Current course title", "string", "course", "title"),
                "term_code" => $field("Current section term", "string", "term", "code"),
            ],
        ];
    }
}
