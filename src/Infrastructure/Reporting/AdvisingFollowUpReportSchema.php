<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Minimal current operational metadata; conversations and authoritative decisions stay private. */
final class AdvisingFollowUpReportSchema
{
    public static function dataset(): array
    {
        $base = StudentStandingReportSchema::dataset();
        $relationships = $base["relationships"];
        $relationships["student"]["parent"] = $relationships["pursuit"]["parent"] = "checkin";
        $fields = array_intersect_key(
            $base["fields"],
            array_flip([
                "student_id",
                "institutional_id",
                "full_name",
                "given_name",
                "family_name",
                "program_code",
                "program_title",
            ]),
        );
        $fields["active"] = [
            "label" => "Active student",
            "type" => "boolean",
            "relation" => "student",
            "column" => "active",
            "permissions" => [],
        ];
        $field = static fn(string $label, string $type, string $column): array => [
            "label" => $label,
            "type" => $type,
            "relation" => "checkin",
            "column" => $column,
            "permissions" => [],
        ];
        return [
            "id" => "advising_followups",
            "table" => "sis_advising_checkins",
            "root" => "checkin",
            "source_scope" => "advising_followups",
            "student_population" => true,
            "population_actions" => ["messaging"],
            "label" => "Advising follow-up",
            "description" =>
                "One current check-in per row, including historical pursuits. " .
                "Names, identifiers and program labels are current. " .
                "Dates and statuses describe working follow-up, not academic decisions or appointments. " .
                "Conversations, private notes and earlier-account records are excluded. " .
                "Count distinct students for headcounts.",
            "permissions" => ["sis:student:read", "sis:advising:checkin"],
            "relationships" => $relationships,
            "fields" =>
                [
                    "checkin_id" => $field("Check-in reference", "string", "id"),
                    "held_on" => $field("Check-in held on", "date", "held_on"),
                    "due_on" => $field("Follow-up due", "date", "due_on"),
                    "recorded_on" => $field("Recorded date (UTC)", "date", "recorded_on"),
                    "responded_on" => $field("Response date (UTC)", "date", "responded_on"),
                    "resolved_on" => $field("Resolution date (UTC)", "date", "resolved_on"),
                    "followup_status" => $field("Follow-up status", "string", "status") + [
                        "choices" => [
                            "open" => "Awaiting student response",
                            "responded" => "Ready for advisor review",
                            "closed" => "Closed",
                            "cancelled" => "Cancelled",
                        ],
                    ],
                    "pursuit_id" => $field("Program pursuit reference", "string", "pursuit_id"),
                ] + $fields,
        ];
    }
}
