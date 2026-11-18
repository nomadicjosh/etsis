<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExcusedScoresTest extends TestCase
{
    private function score(?int $earned, bool $excused = false, int $bonus = 0): array
    {
        return [
            "assignment_id" => EntityId::generate()->value,
            "title" => "Fictional task",
            "possible_hundredths" => 1000,
            "bonus_hundredths" => $bonus,
            "earned_hundredths" => $earned,
            "excused" => $excused,
        ];
    }

    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testExcusedZeroIsSeparateFromPendingWorkAndExcludedFromCoverage(string $mode): void
    {
        $a = $this->score(0, true);
        $b = $this->score(800);
        $c = $this->score(null);
        $ids = array_column([$a, $b, $c], "assignment_id");
        $weights = $mode === "weighted" ? array_combine($ids, [4000, 4000, 2000]) : [];
        $categories =
        $mode === "category"
        ? [["title" => "Work", "weight_hundredths" => 10000, "assignments" => $ids]]
        : [];
        $result = new GradebookCalculation($mode, $weights, $categories)->summarize([$a, $b, $c]);
        self::assertSame(8000, $result["percent_hundredths"]);
        self::assertSame($mode === "weighted" ? 6667 : 5000, $result["coverage_hundredths"]);
        self::assertSame(
            [1, 1, 1, 0],
            [$result["graded"], $result["pending"], $result["excused"], $result["excluded"]],
        );
        self::assertSame(0, $result["excused_assignments"][0]["earned_hundredths"]);
        self::assertSame($a["assignment_id"], $result["excused_assignments"][0]["assignment_id"]);
    }

    public function testFullyExcusedCategoryIsNotAnUnfinishedCategory(): void
    {
        $a = $this->score(0, true);
        $b = $this->score(null, true);
        $c = $this->score(900);
        $categories = [
            [
                "title" => "Excused",
                "weight_hundredths" => 6000,
                "assignments" => [$a["assignment_id"], $b["assignment_id"]],
            ],
            ["title" => "Remaining", "weight_hundredths" => 4000, "assignments" => [$c["assignment_id"]]],
        ];
        $result = new GradebookCalculation("category", [], $categories)->summarize([$a, $b, $c]);
        self::assertSame(9000, $result["percent_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
        self::assertSame(0, $result["awaiting_categories"]);
        self::assertTrue($result["categories"][0]["fully_excused"]);
        self::assertSame(2, $result["excused"]);
        $categories[1]["assignments"] = [];
        $result = new GradebookCalculation("category", [], $categories)->summarize([$a, $b]);
        self::assertNull($result["percent_hundredths"]);
        self::assertSame(0, $result["coverage_hundredths"]);
        self::assertSame(1, $result["awaiting_categories"]);
        self::assertFalse($result["categories"][1]["fully_excused"]);
    }

    #[DataProvider("modes")]
    public function testAnEntirelyExcusedBookReturnsNoAverageOrPendingWork(string $mode): void
    {
        $a = $this->score(700, true);
        $b = $this->score(null, true);
        $ids = array_column([$a, $b], "assignment_id");
        $result = new GradebookCalculation(
            $mode,
            $mode === "weighted" ? array_combine($ids, [5000, 5000]) : [],
            $mode === "category"
                ? [["title" => "Work", "weight_hundredths" => 10000, "assignments" => $ids]]
                : [],
        )->summarize([$a, $b]);
        self::assertNull($result["percent_hundredths"]);
        self::assertSame(
            [0, 0, 2, 0],
            [$result["graded"], $result["pending"], $result["excused"], $result["coverage_hundredths"]],
        );
    }

    public function testExcusalsAreNeitherDropCandidatesNorCountedBonusPoints(): void
    {
        $a = $this->score(1200, true, 200);
        $b = $this->score(500);
        $c = $this->score(1100, false, 200);
        $ids = array_column([$a, $b, $c], "assignment_id");
        $calculation = new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Work",
                    "weight_hundredths" => 10000,
                    "assignments" => $ids,
                    "drop_lowest" => 1,
                ],
            ],
        );
        $result = $calculation->summarize([$a, $b, $c]);
        self::assertSame(11000, $result["percent_hundredths"]);
        self::assertSame(100, $result["bonus_earned_hundredths"]);
        self::assertSame(
            [$b["assignment_id"]],
            array_column($result["dropped_assignments"], "assignment_id"),
        );
        self::assertSame(1, $result["excused"]);
        $b["earned_hundredths"] = null;
        self::assertSame(0, $calculation->summarize([$a, $b, $c])["dropped"]);
    }

    public function testExcusalKeepsTheMarkButCannotHideAnInvalidScore(): void
    {
        $a = EntityId::generate();
        $s = EntityId::generate();
        $score = new AssignmentScore($a, $s, 900, 1000, excused: true);
        self::assertSame(900, $score->earnedHundredths);
        self::assertNull($score->percentageHundredths());
        $this->expectException(ValidationException::class);
        new AssignmentScore($a, $s, 1001, 1000, excused: true);
    }

    public static function invalidFlags(): array
    {
        return [[1], [0], ["true"], [null], [[]]];
    }

    public static function rubricModes(): array
    {
        return [["weighted"], ["category"]];
    }

    #[DataProvider("rubricModes")]
    public function testExplicitExcusalsStayVisibleWhenRubricMembershipWouldAlsoExcludeThem(
        string $mode,
    ): void {
        $a = $this->score(0, true);
        $b = $this->score(null, true);
        $c = $this->score(800);
        $result = new GradebookCalculation(
            $mode,
            $mode === "weighted" ? [$a["assignment_id"] => 0, $c["assignment_id"] => 10000] : [],
            $mode === "category"
                ? [
                    ["title" => "Excluded", "weight_hundredths" => 0, "assignments" => [$a["assignment_id"]]],
                    [
                        "title" => "Active",
                        "weight_hundredths" => 10000,
                        "assignments" => [$c["assignment_id"]],
                    ],
                ]
                : [],
        )->summarize([$a, $b, $c]);
        self::assertSame(8000, $result["percent_hundredths"]);
        self::assertSame(
            [1, 0, 2, 0],
            [$result["graded"], $result["pending"], $result["excused"], $result["excluded"]],
        );
        self::assertCount(2, $result["excused_assignments"]);
    }

    #[DataProvider("invalidFlags")]
    public function testReadInputsRequireAnActualBooleanFlag(mixed $flag): void
    {
        $score = $this->score(500);
        $score["excused"] = $flag;
        $this->expectException(ValidationException::class);
        new GradebookCalculation()->summarize([$score]);
    }
}
