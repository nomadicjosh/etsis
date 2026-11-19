<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

final class WithdrawnCreditQuantities
{
    /** An unknown grade deliberately leaves its target GPA quantity unknown.
     * @param array<string,mixed> $credit
     * @param array<string,mixed>|null $grade
     * @return array<string,int|null>
     */
    public static function summary(array $credit, ?array $grade): array
    {
        return [
            "attempted_milli" => $credit["attempted_milli"],
            "completed_milli" => $credit["completed_milli"],
            "grade_points_milli" => $credit["grade_points_milli"],
            "expected_completed_milli" => 0,
            "expected_grade_points_milli" =>
                $grade === null
                    ? null
                    : ($grade["counts_gpa"]
                        ? intdiv($credit["attempted_milli"] * $grade["points_milli"], 1000)
                        : 0),
        ];
    }

    /** @param array<string,mixed> $credit
     * @param array<string,mixed>|null $grade
     */
    public static function requiresReview(array $credit, ?array $grade): bool
    {
        if ($credit["status"] !== "W") {
            return false;
        }
        $summary = self::summary($credit, $grade);
        return $summary["completed_milli"] !== 0 ||
        $summary["grade_points_milli"] !== ($summary["expected_grade_points_milli"] ?? 0);
    }
}
