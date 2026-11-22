<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Internal projection of verified ledger facts; no raw events or protected reasons. */
final class AcademicWorkView
{
    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function fromLedger(string $id, array $input, ?string $currentTermCode = null): array
    {
        $facts = $input["credit"];
        return [
            "id" => $id,
            "course_id" => $facts["course_id"],
            "course_code" => $facts["course_code"],
            "course_title" => $facts["course_title"],
            "credit_type" => $facts["credit_type"],
            ...array_key_exists('transfer_institution_id', $input)
                ? ['transfer_institution_id' => $input['transfer_institution_id']] : [],
            "term_id" => $facts["term_id"],
            "term_code" => $input["retained_term_code"] ?? $currentTermCode,
            "term_label_retained" => $input["retained_term_code"] !== null,
            "completed_on" => $input["completed_on"],
            "academic_on" => $input['academic_on'] ?? null,
            "attempted_milli" => $facts["attempted_milli"],
            "completed_milli" => $facts["completed_milli"],
            "quality_points_milli" => $facts["grade_points_milli"],
            "grade" => $input["grade"],
            "grade_recorded" => $facts["grade_id"] !== null,
            "status" => $facts["status"],
            "course_version_id" => $facts["course_version_id"] ?? ($input["course_version_id"] ?? null),
            "section_version_id" => $input["section_version_id"],
            "registration_course_id" => $input["registration_course_id"] ?? $facts["course_id"],
            "registration_course_version_id" => $input["registration_course_version_id"] ?? null,
            "registration_term_id" => $input["registration_term_id"] ?? $facts["term_id"],
            "history_recorded" => $input["history_recorded"],
            "earlier_history_unknown" => $input["earlier_history_unknown"],
            "history_version" => $input["history_version"],
        ];
    }
}
