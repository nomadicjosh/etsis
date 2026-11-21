<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** One immutable retained determination; never a recalculation of current load or a census inference. */
final class LoadDeterminationReportSchema
{
    public static function dataset(): array
    {
        $field = static fn(string $label, string $type, string $column): array => [
            "label" => $label,
            "type" => $type,
            "relation" => "determination",
            "column" => $column,
            "permissions" => [],
        ];
        $base = StudentStandingReportSchema::dataset();
        $relationships = $base["relationships"];
        $relationships["student"]["parent"] = $relationships["pursuit"]["parent"] = "determination";
        $fields = array_intersect_key(
            $base["fields"],
            array_flip([
                "student_id",
                "institutional_id",
                "full_name",
                "given_name",
                "family_name",
            ]),
        );
        return [
            "student_population" => true,
            "table" => "sis_load_determinations",
            "root" => "determination",
            "label" => "Recorded enrollment load",
            "description" =>
                "One row per retained current-term determination. Earlier determinations stay separate; " .
                "these are not a reconstructed census or today’s totals. Count distinct students for headcounts.",
            "permissions" => ["sis:student:read", "sis:load:determine"],
            "relationships" => $relationships,
            "fields" =>
                [
                    "determination_id" => $field("Determination reference", "string", "id"),
                    "determined_on" => $field("Determination date", "date", "determined_on"),
                    "recorded_at" => $field("Recorded UTC", "datetime", "recorded_at"),
                    "term_code" => $field("Recorded registration term", "string", "term_code"),
                    "program_code" => $field("Recorded program code", "string", "program_code"),
                    "program_title" => $field("Recorded program title", "string", "program_title"),
                    "classification_code" => $field(
                        "Recorded load code",
                        "string",
                        "classification_code",
                    ),
                    "classification_title" => $field(
                        "Recorded load label",
                        "string",
                        "classification_title",
                    ),
                    "registered_credits" => $field(
                        "Recorded registered credits",
                        "double",
                        "registered_credits_milli",
                    ) + ["scale" => 1000, "root_metric" => true],
                    "enrollment_count" => $field(
                        "Recorded active registrations",
                        "integer",
                        "enrollment_count",
                    ) + ["root_metric" => true],
                    "policy_source" => $field(
                        "Recorded policy scope",
                        "string",
                        "policy_source",
                    ) + ["choices" => ["global" => "Institution", "program" => "Program"]],
                ] + $fields,
        ];
    }
}
