<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DroppedScoresTest extends TestCase
{
    private function score(int $possible, ?int $earned, string $title = "Fictional task"): array
    {
        return [
            "assignment_id" => EntityId::generate()->value,
            "possible_hundredths" => $possible,
            "earned_hundredths" => $earned,
            "title" => $title,
        ];
    }

    private function summary(array $scores, int $drop = 1): array
    {
        return new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Coursework",
                    "weight_hundredths" => 10000,
                    "assignments" => array_column($scores, "assignment_id"),
                    "drop_lowest" => $drop,
                ],
            ],
        )->summarize($scores);
    }

    public function testLowestPercentageIsOmittedWithItsPossiblePointsWhilePendingWorkStaysPending(): void
    {
        $scores = [
            $this->score(10000, 5000),
            $this->score(1000, 800),
            $this->score(10000, 9000),
            $this->score(5000, null),
        ];
        $before = $scores;
        $result = $this->summary($scores);
        self::assertSame(8909, $result["percent_hundredths"]);
        self::assertSame(6875, $result["coverage_hundredths"]);
        self::assertSame(
            [2, 1, 0, 1],
            [$result["graded"], $result["pending"], $result["excluded"], $result["dropped"]],
        );
        self::assertSame(
            [$scores[0]["assignment_id"]],
            array_column($result["dropped_assignments"], "assignment_id"),
        );
        self::assertSame(3, $result["categories"][0]["graded"]);
        self::assertSame(4, $result["categories"][0]["assignments"]);
        self::assertSame($before, $scores);
    }

    public function testZeroIsARealDropCandidateAndAnUngradedScoreIsNot(): void
    {
        $zero = $this->score(1000, 0);
        $other = $this->score(1000, 100);
        $result = $this->summary([$zero, $other]);
        self::assertSame(1000, $result["percent_hundredths"]);
        self::assertSame(0, $result["dropped_assignments"][0]["earned_hundredths"]);
        $other["earned_hundredths"] = null;
        $result = $this->summary([$zero, $other], 499);
        self::assertSame(0, $result["percent_hundredths"]);
        self::assertSame(5000, $result["coverage_hundredths"]);
        self::assertSame(0, $result["dropped"]);
        self::assertSame(1, $result["pending"]);
        $zero["earned_hundredths"] = null;
        self::assertNull($this->summary([$zero, $other])["percent_hundredths"]);
        self::assertSame(0, $this->summary([])["dropped"]);
    }

    public function testLargeDropCountRetainsTheHighestGradedTask(): void
    {
        $result = $this->summary(
            [$this->score(1000, 0), $this->score(1000, 500), $this->score(1000, 900)],
            499,
        );
        self::assertSame(9000, $result["percent_hundredths"]);
        self::assertSame(2, $result["dropped"]);
        self::assertSame(1, $result["graded"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
    }

    public function testPercentageComparisonUsesUnroundedRatiosAndStableTieBreaks(): void
    {
        $a = $this->score(1000000, 333333);
        $b = $this->score(3, 1);
        self::assertSame(
            [$a["assignment_id"]],
            array_column(
                $this->summary([$b, $a, $this->score(1, 1)])["dropped_assignments"],
                "assignment_id",
            ),
        );
        $large = $this->score(10000, 5000);
        $small = $this->score(1000, 500);
        $best = $this->score(10000, 10000);
        self::assertSame(
            [$large["assignment_id"]],
            array_column($this->summary([$small, $large, $best])["dropped_assignments"], "assignment_id"),
        );
        $small["possible_hundredths"] = 10000;
        $small["earned_hundredths"] = 5000;
        $ids = [$small["assignment_id"], $large["assignment_id"]];
        sort($ids, SORT_STRING);
        foreach ([[$small, $large, $best], [$best, $large, $small]] as $scores) {
            self::assertSame(
                [$ids[0]],
                array_column($this->summary($scores)["dropped_assignments"], "assignment_id"),
            );
        }
    }

    public function testExcludedCategoriesAreNotTreatedAsDroppedAndExistingCategoriesDefaultToZero(): void
    {
        $a = $this->score(1000, 0);
        $b = $this->score(1000, 1000);
        $categories = [
            [
                "title" => "Excluded",
                "weight_hundredths" => 0,
                "assignments" => [$a["assignment_id"]],
                "drop_lowest" => 2,
            ],
            ["title" => "Active", "weight_hundredths" => 10000, "assignments" => [$b["assignment_id"]]],
        ];
        $result = new GradebookCalculation("category", [], $categories)->summarize([$a, $b]);
        self::assertSame(10000, $result["percent_hundredths"]);
        self::assertSame(1, $result["excluded"]);
        self::assertSame(0, $result["dropped"]);
        self::assertSame(0, $result["categories"][1]["drop_lowest"]);
        $off = $this->summary([$a, $b], 0);
        self::assertSame(5000, $off["percent_hundredths"]);
        self::assertSame([], $off["dropped_assignments"]);
    }

    public function testCategoryWeightsRemainUnchangedWhenOneCategoryDropsAScore(): void
    {
        $a = $this->score(1000, 500);
        $b = $this->score(1000, 1000);
        $c = $this->score(1000, 800);
        $result = new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Quizzes",
                    "weight_hundredths" => 4000,
                    "assignments" => [$a["assignment_id"], $b["assignment_id"]],
                    "drop_lowest" => 1,
                ],
                ["title" => "Exam", "weight_hundredths" => 6000, "assignments" => [$c["assignment_id"]]],
            ],
        )->summarize([$a, $b, $c]);
        self::assertSame(8800, $result["percent_hundredths"]);
        self::assertSame([4000, 6000], array_column($result["categories"], "weight_hundredths"));
        self::assertSame(10000, $result["coverage_hundredths"]);
    }

    public static function invalidCounts(): array
    {
        return [[-1], [500], [1.5], ["1"], [true], [null], [[]]];
    }

    #[DataProvider("invalidCounts")]
    public function testDropCountsMustBeBoundedIntegers(mixed $count): void
    {
        $this->expectException(ValidationException::class);
        new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Coursework",
                    "weight_hundredths" => 10000,
                    "assignments" => [],
                    "drop_lowest" => $count,
                ],
            ],
        );
    }
}
