<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluationExpirationTest extends TestCase
{
    public static function boundaries(): array
    {
        return [
            ["2026-08-10", 60, "2021-08-10"],
            ["2024-03-31", 1, "2024-02-29"],
            ["2025-03-31", 1, "2025-02-28"],
            ["2000-03-31", 1, "2000-02-29"],
            ["1900-03-31", 1, "1900-02-28"],
            ["2024-02-29", 12, "2023-02-28"],
            ["2026-01-31", 1200, "1926-01-31"],
            ["0001-01-01", 12, "0001-01-01"],
        ];
    }

    #[DataProvider("boundaries")]
    public function testCalendarAgeHasAnInclusiveClampedBoundary(
        string $review,
        int $months,
        string $earliest,
    ): void {
        $policy = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "max_age_months_at_evaluation" => $months,
            ]),
        );
        self::assertSame(
            ["evaluated_on" => $review, "earliest_completion_on" => $earliest],
            $policy->evaluationContext($review),
        );
        self::assertNull($policy->completionStatus($earliest, evaluatedOn: $review));
        if ($earliest !== "0001-01-01") {
            $before = new \DateTimeImmutable($earliest)->modify("-1 day")->format("Y-m-d");
            self::assertSame(
                "completion_expired",
                $policy->completionStatus($before, evaluatedOn: $review)?->value,
            );
        }
        self::assertSame(
            "completion_date_unknown",
            $policy->completionStatus(evaluatedOn: $review)?->value,
        );
        self::assertSame("evaluation_date_unknown", $policy->completionStatus($earliest)?->value);
    }

    public static function invalidAges(): array
    {
        return [[0], [-1], [1201], ["12"], [12.5], [false], [[]]];
    }

    #[DataProvider("invalidAges")]
    public function testInvalidReviewAgeIsRejected(mixed $months): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "max_age_months_at_evaluation" => $months,
            ]),
        );
    }

    public function testIndependentPursuitAgeAndFixedWindowStillIntersect(): void
    {
        $facts = array_replace(GroupWorkEligibility::DEFAULT, [
            "max_age_months_at_pursuit_start" => 24,
            "max_age_months_at_evaluation" => 12,
            "completed_through" => "2026-01-01",
        ]);
        $policy = new GroupWorkEligibility($facts);
        self::assertNull($policy->completionStatus("2025-08-10", "2026-01-31", "2026-08-10"));
        self::assertSame(
            "completion_expired",
            $policy->completionStatus("2025-08-09", "2026-01-31", "2026-08-10")?->value,
        );
        self::assertSame(
            "completed_after_window",
            $policy->completionStatus("2026-01-02", "2026-01-31", "2026-08-10")?->value,
        );
        self::assertSame(
            "completion_too_old",
            $policy->completionStatus("2023-01-01", "2026-01-31", "2026-08-10")?->value,
        );
        unset($facts["max_age_months_at_evaluation"]);
        self::assertSame($facts, new GroupWorkEligibility($facts)->facts);
        self::assertNull(
            new GroupWorkEligibility($facts)->completionStatus("2025-01-31", "2026-01-31"),
        );
    }

    public function testInvalidEvaluationDateCannotBecomeAnAgeBoundary(): void
    {
        $policy = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, ["max_age_months_at_evaluation" => 12]),
        );
        $this->expectException(InvalidArgumentException::class);
        $policy->evaluationContext("2026-02-30");
    }
}
