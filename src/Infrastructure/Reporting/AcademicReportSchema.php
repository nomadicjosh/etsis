<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

final class AcademicReportSchema
{
    /** @return array<string, array<string, mixed>> */
    public static function datasets(): array
    {
        $studentRead = ["sis:student:read"];
        $join = static fn(
            string $table,
            string $parent,
            string $left,
            string $right,
            array $permissions = [],
        ): array => [
            "table" => $table,
            "parent" => $parent,
            "left" => $left,
            "right" => $right,
            "permissions" => $permissions,
            "label" => match ($table) {
                "sis_students" => "Student records",
                "sis_persons" => "Student names",
                "sis_person_identifiers" => "Institutional IDs",
                "sis_programs" => "Academic programs",
                "sis_terms" => "Academic terms",
                "sis_student_catalogs" => "Assigned curricula",
                "sis_program_catalogs" => "Program catalog revisions",
                "sis_academic_years" => "Academic years",
                "sis_grades" => "Final grades",
                "sis_courses" => "Courses",
                "sis_course_descriptions" => "Course descriptions",
                "sis_credit_course_versions" => "Academic credit course versions",
                "sis_section_course_versions" => "Section course definitions",
                "sis_course_versions" => "Retained course definitions",
                "sis_section_delivery" => "Delivery formats",
                "sis_enrollments" => "Enrollments",
                default => "Related records",
            },
        ];
        $field = static fn(
            string $label,
            string $type,
            string $relation,
            string $column,
            array $permissions = [],
        ): array => [
            "label" => $label,
            "type" => $type,
            "relation" => $relation,
            "column" => $column,
            "permissions" => $permissions,
        ];
        $personJoins = [
            "person" => $join("sis_persons", "student", "person_id", "id"),
            "identity" => $join("sis_person_identifiers", "person", "id", "person_id"),
        ];
        $personFields = [
            "student_id" => $field("Student reference", "string", "student", "id"),
            "institutional_id" => $field("Institutional ID", "string", "identity", "institutional_id"),
            "full_name" => $field("Student full name", "string", "person", "given_name") + [
                "expression" => "full_name",
            ],
            "given_name" => $field("Given name", "string", "person", "given_name"),
            "family_name" => $field("Family name", "string", "person", "family_name"),
            "active" => $field("Active student", "boolean", "student", "active"),
        ];
        $program = [
            "table" => "sis_student_programs",
            "root" => "assignment",
            "label" => "Student programs & catalog cohorts",
            "description" => "One row per student program assignment, including its assigned catalog year.",
            "permissions" => $studentRead,
            "relationships" => [
                "student" => $join("sis_students", "assignment", "student_id", "id"),
                ...$personJoins,
                "program" => $join("sis_programs", "assignment", "program_id", "id"),
                "term" => $join("sis_terms", "assignment", "entry_term_id", "id"),
                "binding" => $join("sis_student_catalogs", "assignment", "id", "assignment_id"),
                "catalog" => $join("sis_program_catalogs", "binding", "catalog_id", "id"),
                "year" => $join("sis_academic_years", "catalog", "year_id", "id"),
            ],
            "fields" => $personFields + [
                "program_code" => $field("Program code", "string", "program", "code"),
                "program_title" => $field("Program title", "string", "program", "title"),
                "entry_term" => $field("Program entry term", "string", "term", "code"),
                "assignment_status" => $field("Program assignment status", "string", "assignment", "status"),
                "catalog_year" => $field("Matriculation academic year", "string", "year", "code"),
                "catalog_revision" => $field("Assigned catalog revision", "integer", "catalog", "revision"),
            ],
        ];
        $credits = [
            "table" => "sis_academic_credits",
            "root" => "credit",
            "label" => "Academic credits & final grades",
            "description" => "One row per academic credit snapshot. Historical course labels are preserved.",
            "permissions" => $studentRead,
            "relationships" => [
                "student" => $join("sis_students", "credit", "student_id", "id"),
                ...$personJoins,
                "term" => $join("sis_terms", "credit", "term_id", "id"),
                "grade" => $join("sis_grades", "credit", "grade_id", "id"),
                "course_binding" => $join("sis_credit_course_versions", "credit", "id", "id"),
            ],
            "fields" => $personFields + [
                "course_version_id" => $field(
                    "Retained course version",
                    "string",
                    "course_binding",
                    "course_version_id",
                ),
                "course_code" => $field("Recorded course code", "string", "credit", "course_code"),
                "course_title" => $field("Recorded course title", "string", "credit", "course_title"),
                "term_code" => $field("Academic term", "string", "term", "code"),
                "term_starts_on" => $field("Term begins", "date", "term", "starts_on"),
                "grade_code" => $field("Final grade", "string", "grade", "code"),
                "grade_scale_version" => $field(
                    "Recorded grade scale publication",
                    "string",
                    "grade",
                    "scale_version_id",
                ),
                "grade_units" => $field("Grade-point units", "double", "grade", "points_milli") + [
                    "scale" => 1000,
                ],
                "status" => $field("Academic credit status", "string", "credit", "status"),
                "status_label" => $field("Readable academic status", "string", "credit", "status") + [
                    "expression" => "registration_label",
                ],
                "credit_type" => $field("Credit type", "string", "credit", "credit_type"),
                "attempted" => $field("Attempted credits", "double", "credit", "attempted_milli") + [
                    "scale" => 1000,
                ],
                "completed" => $field("Earned credits", "double", "credit", "completed_milli") + [
                    "scale" => 1000,
                ],
            ],
        ];
        $sections = [
            "table" => "sis_sections",
            "root" => "section",
            "label" => "Sections, teaching & enrollment counts",
            "description" => "One row per section; enrollment relationships can expand to registration rows.",
            "permissions" => ["sis:catalog:manage"],
            "relationships" => [
                "course" => $join("sis_courses", "section", "course_id", "id"),
                "course_text" => $join("sis_course_descriptions", "course", "id", "course_id"),
                "course_binding" => $join("sis_section_course_versions", "section", "id", "id"),
                "course_version" => $join("sis_course_versions", "course_binding", "course_version_id", "id"),
                "term" => $join("sis_terms", "section", "term_id", "id"),
                "delivery" => $join("sis_section_delivery", "section", "id", "section_id"),
                "enrollment" => $join("sis_enrollments", "section", "id", "section_id", $studentRead) + [
                    "many" => true,
                ],
                "student" => $join("sis_students", "enrollment", "student_id", "id", $studentRead),
                ...$personJoins,
            ],
            "fields" => [
                "section_code" => $field("Section code", "string", "section", "code"),
                "retained_course_version_id" => $field(
                    "Retained course version",
                    "string",
                    "course_version",
                    "id",
                ),
                "retained_course_code" => $field("Retained course code", "string", "course_version", "code"),
                "retained_course_title" => $field(
                    "Retained catalog title",
                    "string",
                    "course_version",
                    "title",
                ),
                "retained_transcript_title" => $field(
                    "Retained transcript title",
                    "string",
                    "course_version",
                    "transcript_title",
                ),
                "retained_course_credits" => $field(
                    "Retained credits",
                    "double",
                    "course_version",
                    "credits_milli",
                ) + ["scale" => 1000],
                "published_transcript_title" => $field(
                    "Current published transcript title",
                    "string",
                    "course_text",
                    "published_transcript_title",
                ),
                "published_course_description" => $field(
                    "Current published course description",
                    "string",
                    "course_text",
                    "published_description",
                ),
                "course_code" => $field("Current course code", "string", "course", "code"),
                "course_title" => $field("Current catalog title", "string", "course", "title"),
                "term_code" => $field("Academic term", "string", "term", "code"),
                "capacity" => $field("Section capacity", "integer", "section", "capacity"),
                "delivery_mode" => $field("Delivery mode", "string", "delivery", "mode"),
                "enrollment_id" => $field("Enrollment reference", "string", "enrollment", "id", $studentRead),
                "enrollment_status" => $field(
                    "Enrollment status",
                    "string",
                    "enrollment",
                    "status",
                    $studentRead,
                ),
                "institutional_id" => $field(
                    "Student institutional ID",
                    "string",
                    "identity",
                    "institutional_id",
                    $studentRead,
                ),
            ],
        ];
        return [
            "programs" => ["id" => "programs"] + $program,
            "credits" => ["id" => "credits"] + $credits,
            "sections" => ["id" => "sections"] + $sections,
        ];
    }
}
