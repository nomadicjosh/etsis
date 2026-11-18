<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BonusOnlyTest extends TestCase
{
    private function score(int $possible, ?int $earned, int $bonus = 0): array
    {
        return [
            "assignment_id" => EntityId::generate()->value,
            "possible_hundredths" => $possible,
            "earned_hundredths" => $earned,
            "bonus_hundredths" => $bonus,
        ];
    }

    private function calculation(string $mode, array $scores, int $drop = 0): GradebookCalculation
    {
        $regular = array_values(
            array_filter($scores, static fn(array $s): bool => $s["possible_hundredths"] > 0),
        );
        $bonus = array_column(
            array_filter($scores, static fn(array $s): bool => $s["possible_hundredths"] === 0),
            "assignment_id",
        );
        $weights = [];
        foreach ($regular as $score) {
            $weights[$score["assignment_id"]] = intdiv(10000, count($regular));
        }
        foreach ($bonus as $id) {
            $weights[$id] = 1000;
        }
        return new GradebookCalculation(
            $mode,
            $mode === "weighted" ? $weights : [],
            $mode === "category"
                ? [
                    [
                        "title" => "Coursework",
                        "weight_hundredths" => 10000,
                        "assignments" => array_column($scores, "assignment_id"),
                        "drop_lowest" => $drop,
                    ],
                ]
                : [],
            $bonus,
        );
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testBonusOnlyWorkAddsWithoutRequiredCoverageOrAZeroDenominator(string $mode): void
    {
        $scores = [$this->score(10000, 9200), $this->score(0, 500, 1000)];
        $calculation = $this->calculation($mode, $scores);
        $result = $calculation->summarize($scores);
        self::assertSame(9700, $result["percent_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
        self::assertSame(500, $result["bonus_earned_hundredths"]);
        self::assertSame(0, $result["pending"]);
        self::assertSame(1, $result["bonus_only"]);
        self::assertSame(0, $result["bonus_pending"]);
        $scores[1]["earned_hundredths"] = null;
        $result = $calculation->summarize($scores);
        self::assertSame(9200, $result["percent_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
        self::assertSame(0, $result["pending"]);
        self::assertSame(1, $result["bonus_pending"]);
        $scores[1]["earned_hundredths"] = 0;
        $result = $calculation->summarize($scores);
        self::assertSame(9200, $result["percent_hundredths"]);
        self::assertSame(2, $result["graded"]);
        self::assertSame(0, $result["bonus_pending"]);
    }

    #[DataProvider("modes")]
    public function testExcusalsPreserveBonusMarksAndNeverInventAnAverage(string $mode): void
    {
        $scores = [$this->score(10000, null), $this->score(0, 500, 1000)];
        $calculation = $this->calculation($mode, $scores);
        $result = $calculation->summarize($scores);
        self::assertNull($result["percent_hundredths"]);
        self::assertSame(0, $result["bonus_earned_hundredths"]);
        self::assertSame(500, $result["unapplied_bonus_hundredths"]);
        $scores[0]["earned_hundredths"] = 9200;
        $scores[1]["excused"] = true;
        $result = $calculation->summarize($scores);
        self::assertSame(9200, $result["percent_hundredths"]);
        self::assertSame(1, $result["excused"]);
        self::assertSame(500, $result["excused_assignments"][0]["earned_hundredths"]);
        $scores[1]["excused"] = false;
        $scores[0]["excused"] = true;
        $result = $calculation->summarize($scores);
        self::assertNull($result["percent_hundredths"]);
        self::assertSame(0, $result["coverage_hundredths"]);
        self::assertSame(500, $result["unapplied_bonus_hundredths"]);
    }

    public function testWeightedBonusIsAFixedIncrementAfterRegularNormalization(): void
    {
        $scores = [$this->score(10000, 8000), $this->score(10000, null), $this->score(0, 500, 1000)];
        $result = $this->calculation("weighted", $scores)->summarize($scores);
        self::assertSame(8500, $result["percent_hundredths"]);
        self::assertSame(5000, $result["coverage_hundredths"]);
        self::assertSame(1, $result["pending"]);
        $scores[1]["excused"] = true;
        $result = $this->calculation("weighted", $scores)->summarize($scores);
        self::assertSame(8500, $result["percent_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
    }

    public function testDropLowestRanksRegularWorkAndAlwaysKeepsBonusWork(): void
    {
        $scores = [$this->score(1000, 100), $this->score(1000, 900), $this->score(0, 100, 1000)];
        $result = $this->calculation("category", $scores, 10)->summarize($scores);
        self::assertSame(10000, $result["percent_hundredths"]);
        self::assertSame(
            [$scores[0]["assignment_id"]],
            array_column($result["dropped_assignments"], "assignment_id"),
        );
        self::assertSame(100, $result["bonus_earned_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
    }

    public function testBonusOnlyCategoryCannotBorrowAnotherCategorysDenominator(): void
    {
        $scores = [$this->score(10000, 8000), $this->score(0, 500, 1000)];
        $result = new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Regular",
                    "weight_hundredths" => 6000,
                    "assignments" => [$scores[0]["assignment_id"]],
                ],
                [
                    "title" => "Extra",
                    "weight_hundredths" => 4000,
                    "assignments" => [$scores[1]["assignment_id"]],
                ],
            ],
        )->summarize($scores);
        self::assertSame(8000, $result["percent_hundredths"]);
        self::assertSame(6000, $result["coverage_hundredths"]);
        self::assertSame(1, $result["awaiting_categories"]);
        self::assertSame(500, $result["unapplied_bonus_hundredths"]);
        self::assertNull($result["categories"][1]["percent_hundredths"]);
        self::assertSame(0, $result["pending"]);
    }

    public function testUnassignedWeightedBonusIsExcludedAndNoWorkCreatesNoAverage(): void
    {
        $scores = [$this->score(10000, 8000), $this->score(0, 500, 1000)];
        $calculation = new GradebookCalculation("weighted", [
            $scores[0]["assignment_id"] => 10000,
        ]);
        $result = $calculation->summarize($scores);
        self::assertSame(8000, $result["percent_hundredths"]);
        self::assertSame(1, $result["excluded"]);
        self::assertSame(0, $result["bonus_only"]);
        self::assertNull(new GradebookCalculation()->summarize([$scores[1]])["percent_hundredths"]);
        $calculation = new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Ignored",
                    "weight_hundredths" => 0,
                    "assignments" => [$scores[1]["assignment_id"]],
                ],
                [
                    "title" => "Work",
                    "weight_hundredths" => 10000,
                    "assignments" => [$scores[0]["assignment_id"]],
                ],
            ],
        );
        self::assertSame(0, $calculation->summarize($scores)["bonus_earned_hundredths"]);
    }

    public function testBonusOnlyScoresHaveNoStandalonePercentageAndStillEnforceTheLimit(): void
    {
        $a = EntityId::generate();
        $s = EntityId::generate();
        self::assertNull(new AssignmentScore($a, $s, 100, 0, 200)->percentageHundredths());
        self::assertNull(new AssignmentScore($a, $s, 0, 0, 200)->percentageHundredths());
        self::assertNull(new AssignmentScore($a, $s, null, 0, 200)->percentageHundredths());
        $this->expectException(ValidationException::class);
        new AssignmentScore($a, $s, 201, 0, 200);
    }

    public function testUnknownBonusClassificationCannotDistortRegularWeights(): void
    {
        $score = $this->score(0, 100, 200);
        $this->expectException(ValidationException::class);
        new GradebookCalculation("weighted", [
            $score["assignment_id"] => 10000,
        ])->summarize([$score]);
    }

    public function testBonusLimitsCannotReplaceRegularWeightsOrExceedTheCombinedCeiling(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $c = EntityId::generate()->value;
        foreach ([[$a => 9000, $b => 1000], [$a => 10000, $b => 10000, $c => 1]] as $weights) {
            try {
                new GradebookCalculation("weighted", $weights, [], [$b, $c]);
                self::fail("Bonus limits replaced regular weights or exceeded their ceiling.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
