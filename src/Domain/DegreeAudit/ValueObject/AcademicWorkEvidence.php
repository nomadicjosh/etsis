<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Curated accepted work facts; never recalculate a grade or choose a repeat-policy winner. */
final class AcademicWorkEvidence
{
    /** @return array<string,mixed> */
    public static function fromAttempt(#[\SensitiveParameter] PolicyAttempt $attempt): array
    {
        if ($attempt->status === "D") {
            throw new CorruptHistoryException();
        }
        $facts = $attempt->grade?->facts;
        $pending =
        $attempt->origin === "institutional" &&
        in_array($attempt->status, ["A", "N"], true) &&
        !$attempt->gradeRecorded &&
        $facts === null &&
        $attempt->earnedMilli === 0 &&
        $attempt->qualityMilli === 0;
        return [
            "credit_type" => $attempt->origin,
            "recorded_earned_milli" => $attempt->earnedMilli,
            "completed_on" => $attempt->completedOn,
            "grade_state" => $facts !== null ? "retained" : ($pending ? "awaiting_grade" : "unknown"),
            "retained_grade" =>
                $facts === null
                    ? null
                    : [
                        "code" => $facts["code"],
                        "points_milli" => $facts["points_milli"],
                        "grading_publication_retained" => $facts["scale_version_id"] !== null,
                    ],
        ];
    }
}
