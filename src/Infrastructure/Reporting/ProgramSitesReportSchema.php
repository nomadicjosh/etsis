<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Current site setup and cohort-bound snapshots have separate joins and declared row grain. */
final class ProgramSitesReportSchema
{
    /** @param array<string,array<string,mixed>> $datasets
     * @return array<string,array<string,mixed>> */
    public static function extend(array $datasets): array
    {
        $field = static fn(
            string $label,
            string $relation,
            string $column,
            string $type = "string",
        ): array => [
            "label" => $label,
            "type" => $type,
            "relation" => $relation,
            "column" => $column,
            "permissions" => [],
        ];
        $datasets["programs"]["relationships"]["program_additional_site"] = [
            "table" => "sis_program_catalog_sites",
            "parent" => "program_definition",
            "left" => "id",
            "right" => "catalog_id",
            "many" => true,
            "label" => "Published additional offering sites",
            "permissions" => [],
        ];
        $datasets["programs"]["fields"] += [
            "program_additional_campus_id" => $field(
                "Published additional campus reference",
                "program_additional_site",
                "campus_id",
            ),
            "program_additional_campus_code" => $field(
                "Published additional campus code",
                "program_additional_site",
                "campus_code",
            ),
            "program_additional_campus_title" => $field(
                "Published additional campus name",
                "program_additional_site",
                "campus_title",
            ),
            "program_additional_campuses_recorded" => $field(
                "Additional program sites recorded",
                "program_definition",
                "additional_campuses_recorded",
                "boolean",
            ),
        ];
        $datasets["catalog_program_sites"] = [
            "id" => "catalog_program_sites",
            "root" => "program",
            "table" => "sis_programs",
            "label" => "Program offering sites",
            "description" => "Current program setup, including programs without additional sites. " .
                "Connecting additional sites adds one row per affiliation. " .
                "These are offering locations, not student attendance assignments.",
            "permissions" => ["sis:catalog:manage"],
            "relationships" => [
                "details" => [
                    "table" => "sis_program_details",
                    "parent" => "program",
                    "left" => "id",
                    "right" => "id",
                    "label" => "Current program details",
                    "permissions" => [],
                ],
                "primary_campus" => [
                    "table" => "sis_catalog_classifications",
                    "parent" => "details",
                    "left" => "campus_id",
                    "right" => "id",
                    "label" => "Primary campus",
                    "permissions" => [],
                ],
                "site" => [
                    "table" => "sis_program_sites",
                    "parent" => "program",
                    "left" => "id",
                    "right" => "program_id",
                    "many" => true,
                    "label" => "Additional offering sites",
                    "permissions" => [],
                ],
                "additional_campus" => [
                    "table" => "sis_catalog_classifications",
                    "parent" => "site",
                    "left" => "campus_id",
                    "right" => "id",
                    "label" => "Additional campus definitions",
                    "permissions" => [],
                ],
            ],
            "fields" => [
                "program_id" => $field("Program reference", "program", "id"),
                "program_code" => $field("Program code", "program", "code"),
                "program_title" => $field("Program title", "program", "title"),
                "primary_campus_code" => $field("Primary campus code", "primary_campus", "code"),
                "primary_campus_title" => $field("Primary campus name", "primary_campus", "title"),
                "additional_campus_id" => $field("Additional campus reference", "additional_campus", "id"),
                "additional_campus_code" => $field("Additional campus code", "additional_campus", "code"),
                "additional_campus_title" => $field("Additional campus name", "additional_campus", "title"),
                "additional_campus_active" => $field(
                    "Additional campus active",
                    "additional_campus",
                    "active",
                    "boolean",
                ),
            ],
        ];
        return $datasets;
    }
}
