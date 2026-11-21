<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Current setup and retained student choices have separate grains and permissions. */
final class CurriculumReportSchema
{
    public static function datasets(): array
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
        $kinds = array_column(
            \Domain\Catalog\ValueObject\CurriculumComponentKind::cases(),
            "value",
        );
        $kindChoices = array_combine($kinds, array_map(ucfirst(...), $kinds));
        $current = [
            "id" => "catalog_components",
            "table" => "sis_program_specializations",
            "root" => "component",
            "label" => "Current curriculum components",
            "description" =>
                "One row per current program-owned component, including retired entries. " .
                "Department, school and required-major labels are current setup; these are not " .
                "student choices or published cohort labels.",
            "permissions" => ["sis:catalog:manage"],
            "relationships" => [
                "program" => $join(
                    "sis_programs",
                    "component",
                    "program_id",
                    "id",
                    "Current programs",
                ),
                "department" => $join(
                    "sis_catalog_classifications",
                    "component",
                    "department_id",
                    "id",
                    "Current responsible department",
                ),
                "school" => $join(
                    "sis_catalog_classifications",
                    "department",
                    "school_id",
                    "id",
                    "Current department school",
                ),
                "major" => $join(
                    "sis_program_specializations",
                    "component",
                    "parent_major_id",
                    "id",
                    "Current required major",
                ),
            ],
            "fields" => [
                "component_id" => $field("Component reference", "string", "component", "id"),
                "component_code" => $field("Current component code", "string", "component", "code"),
                "component_title" => $field(
                    "Current component name",
                    "string",
                    "component",
                    "title",
                ),
                "component_kind" => $field(
                    "Current component type",
                    "string",
                    "component",
                    "kind",
                ) + ["choices" => $kindChoices],
                "component_active" => $field(
                    "Available in current setup",
                    "boolean",
                    "component",
                    "active",
                ),
                "program_id" => $field("Program reference", "string", "component", "program_id"),
                "program_code" => $field("Current program code", "string", "program", "code"),
                "program_title" => $field("Current program name", "string", "program", "title"),
            ],
        ];
        foreach (
            [
                "department" => "Responsible department",
                "school" => "Department school",
                "major" => "Required major",
            ] as $relation => $label
        ) {
            foreach (["code", "title"] as $column) {
                $current["fields"][
                ($relation === "major" ? "parent_major" : $relation) . "_" . $column
                ] = $field(
                    "Current " . strtolower($label) . ($column === "code" ? " code" : " name"),
                    "string",
                    $relation,
                    $column,
                );
            }
        }
        $base = StudentStandingReportSchema::dataset();
        $relationships = array_intersect_key(
            $base["relationships"],
            array_flip(["student", "person", "identity", "pursuit"]),
        );
        $relationships["student"]["parent"] = $relationships["pursuit"]["parent"] = "component";
        $relationships["pursuit"]["left"] = "assignment_id";
        $relationships["binding"] = $join(
            "sis_student_catalogs",
            "pursuit",
            "id",
            "assignment_id",
            "Current cohort binding",
        );
        $fields = array_intersect_key(
            $base["fields"],
            array_flip([
                "student_id",
                "institutional_id",
                "full_name",
                "given_name",
                "family_name",
                "pursuit_status",
            ]),
        );
        foreach (
            [
                "component_row_id" => ["Selection row reference", "string", "id"],
                "pursuit_id" => ["Program pursuit reference", "string", "assignment_id"],
                "component_id" => ["Selected component reference", "string", "component_id"],
                "component_code" => ["Retained component code", "string", "component_code"],
                "component_title" => ["Retained component name", "string", "component_title"],
                "component_kind" => ["Retained component type", "string", "component_kind"],
                "kind_recorded" => ["Component type recorded", "boolean", "kind_recorded"],
                "department_recorded" => ["Department recorded", "boolean", "department_recorded"],
                "parent_major_recorded" => [
                    "Required-major relationship recorded",
                    "boolean",
                    "parent_major_recorded",
                ],
                "program_code" => ["Retained program code", "string", "program_code"],
                "program_title" => ["Retained program name", "string", "program_title"],
                "academic_year_code" => ["Selected catalog year", "string", "academic_year_code"],
                "catalog_revision" => ["Selected catalog revision", "integer", "catalog_revision"],
                "program_version_id" => [
                    "Selected program publication reference",
                    "string",
                    "program_version_id",
                ],
            ] as $id => [$label, $type, $column]
        ) {
            $fields[$id] = $field($label, $type, "component", $column);
        }
        $fields["component_kind"]["choices"] = $kindChoices;
        $fields["cohort_matches"] = $field(
            "Selection matches current cohort",
            "boolean",
            "component",
            "cohort_binding_revision",
        ) + ["expression" => "curriculum_cohort_matches"];
        $fields["current_program_version_id"] = $field(
            "Current pursuit program publication reference",
            "string",
            "binding",
            "catalog_id",
        );
        $fields["current_cohort_binding_revision"] = $field(
            "Current cohort binding revision",
            "integer",
            "binding",
            "version",
        );
        $fields["selected_cohort_binding_revision"] = $field(
            "Selected cohort binding revision",
            "integer",
            "component",
            "cohort_binding_revision",
        );
        foreach (
            [
                "department" => "Responsible department",
                "school" => "Department school",
                "parent_major" => "Required major",
            ] as $prefix => $label
        ) {
            foreach (["code", "title"] as $column) {
                $fields[$prefix . "_" . $column] = $field(
                    "Retained " . strtolower($label) . ($column === "code" ? " code" : " name"),
                    "string",
                    "component",
                    $prefix . "_" . $column,
                );
            }
        }
        $selected = [
            "id" => "selected_components",
            "table" => "sis_student_curriculum_components",
            "root" => "component",
            "label" => "Selected student curriculum",
            "curriculum_components" => true,
            "description" =>
                "One row per currently selected component within a program pursuit, including " .
                "closed or changed-cohort pursuits. Academic labels are retained; student names " .
                "and pursuit status are current. Count distinct students for headcounts. A " .
                "student can appear in several component/department groups.",
            "permissions" => ["sis:student:read"],
            "relationships" => $relationships,
            "fields" => $fields,
        ];
        return ["catalog_components" => $current, "selected_components" => $selected];
    }
}
