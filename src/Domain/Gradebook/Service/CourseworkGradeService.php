<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use Brick\Math\BigRational;
use Domain\Gradebook\Repository\GradebookRepository;
use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Gradebook\ValueObject\CourseworkGradePolicy;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal selected-student assessment. The academic workflow authorizes and locks its callers. */
final readonly class CourseworkGradeService
{
    public function __construct(
        private GradebookRepository $repository,
        private GradeComparisonPolicyService $comparisonPolicies,
    )
    {
    }

    /** @param list<array<string,mixed>> $grades
     * @return array<string,mixed> Evidence stays internal; browser responses remove it.
     */
    public function assess(EntityId $student, EntityId $section, array $grades): array
    {
        $scores = $this->repository->scores($student, $section, limit: GradebookCalculation::LIMIT + 1);
        if (count($scores) > GradebookCalculation::LIMIT) {
            return self::unavailable(
                "This gradebook exceeds the calculation limit. Choose a grade manually.",
            );
        }
        $settings = $this->comparisonPolicies->resolve($this->repository->calculation($section));
        $bonus = array_column(
            array_filter($scores, static fn(array $row): bool => $row["possible_hundredths"] === 0),
            "assignment_id",
        );
        try {
            $summary = new GradebookCalculation(
                $settings["mode"],
                $settings["weights"],
                $settings["categories"],
                $bonus,
            )->summarize($scores, true);
        } catch (\Domain\Gradebook\Exception\CourseworkCalculationLimitException) {
            return self::unavailable(
                "This varied points setup needs manual grade review. The ordinary average remains available.",
            );
        } catch (ValidationException) {
            return self::unavailable("Review gradebook setup and score limits, or choose a grade manually.");
        }
        if (
            $summary["exact_percent"] === null ||
            $summary["pending"] !== 0 ||
            ($summary["awaiting_categories"] ?? 0) !== 0 ||
            ($summary["unapplied_bonus_hundredths"] ?? 0) !== 0
        ) {
            return self::unavailable(
                "Complete the included regular work and review unapplied bonus points before using a suggestion.",
            );
        }
        $policy = new CourseworkGradePolicy($settings["grade_policy"]);
        $raw = BigRational::of($summary["exact_percent"]);
        $score = $policy->apply($raw);
        $comparison = [
            "score_display" => CourseworkGradeEvidence::formatPercent($raw),
            "grading_display" => CourseworkGradeEvidence::formatPercent($score),
            "grade_policy" => $policy->facts,
            'grade_policy_context' => new \Domain\Gradebook\ValueObject\GradeComparisonPolicyReference(
                $settings['grade_policy_reference'],
            )->describe(),
        ];
        if ($score->compareTo(100) > 0) {
            return $comparison +
            self::unavailable(
                "This average exceeds 100%. Apply institutional policy and choose a grade manually.",
                $score,
            );
        }
        $matched = [];
        foreach ($grades as $grade) {
            if (
                ($grade["scale_version_id"] ?? null) !== null &&
                is_int($grade["min_score_milli"] ?? null) &&
                is_int($grade["max_score_milli"] ?? null) &&
                $score->multipliedBy(1000)->compareTo($grade["min_score_milli"]) >= 0 &&
                $score->multipliedBy(1000)->compareTo($grade["max_score_milli"]) <= 0
            ) {
                $matched[] = $grade;
            }
        }
        if (count($matched) !== 1) {
            return $comparison +
            self::unavailable(
                "The policy-adjusted average has no single match in the retained grading ranges. " .
                    "Choose a grade manually.",
                $score,
            );
        }
        $grade = $matched[0];
        $facts = [
            "student_id" => $student->value,
            "section_id" => $section->value,
            "mode" => $settings["mode"],
            "weights" => $settings["weights"],
            "categories" => $settings["categories"],
            "calculation_version" => $settings["revision"],
            "scores" => array_map(
                static fn(array $row): array => [
                    $row["assignment_id"],
                    $row["possible_hundredths"],
                    $row["bonus_hundredths"],
                    $row["earned_hundredths"],
                    $row["excused"],
                    $row["version"],
                ],
                $scores,
            ),
            "grade_id" => $grade["id"],
            "min_score_milli" => $grade["min_score_milli"],
            "max_score_milli" => $grade["max_score_milli"],
            "exact_percent" => $summary["exact_percent"],
            "grade_policy" => $policy->facts,
            "grade_policy_reference" => $settings["grade_policy_reference"],
            "grading_percent" => (string) $score,
        ];
        if (strlen(json_encode($facts, JSON_THROW_ON_ERROR)) > CourseworkGradeEvidence::MAX_BYTES) {
            return $comparison +
            self::unavailable(
                "This gradebook needs manual grade review. Its working average remains available.",
                $score,
            );
        }
        $evidence = new CourseworkGradeEvidence($facts);
        return $evidence->summary() + [
            "available" => true,
            "grade_code" => $grade["code"],
            "evidence" => $evidence,
        ];
    }

    private static function unavailable(string $message, ?BigRational $score = null): array
    {
        return [
            "available" => false,
            "message" => $message,
            ...$score === null ? [] : ["score_display" => CourseworkGradeEvidence::formatPercent($score)],
        ];
    }
}
