<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\ValueObject\AssignmentPoints;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BonusPointsTest extends TestCase
{
    public static function modes(): array
    {
        return [["points"], ["weighted"], ["category"]];
    }

    #[DataProvider("modes")]
    public function testBonusRaisesTheEarnedScoreWhileKeepingTheBaseDenominator(string $mode): void
    {
        $id = EntityId::generate()->value;
        $calculation = new GradebookCalculation(
            $mode,
            $mode === "weighted" ? [$id => 10000] : [],
            $mode === "category"
                ? [["title" => "Work", "weight_hundredths" => 10000, "assignments" => [$id]]]
                : [],
        );
        $scores = [
            [
                "assignment_id" => $id,
                "possible_hundredths" => 10000,
                "bonus_hundredths" => 2000,
                "earned_hundredths" => 11500,
            ],
        ];
        $result = $calculation->summarize($scores);
        self::assertSame(11500, $result["percent_hundredths"]);
        self::assertSame(10000, $result["coverage_hundredths"]);
        self::assertSame(1500, $result["bonus_earned_hundredths"]);
        self::assertTrue($result["bonus_enabled"]);
        $scores[0]["earned_hundredths"] = null;
        self::assertNull($calculation->summarize($scores)["percent_hundredths"]);
        $scores[0]["earned_hundredths"] = 0;
        self::assertSame(0, $calculation->summarize($scores)["percent_hundredths"]);
    }

    public function testDefaultZeroBonusStillRejectsOverMaximumAndKeepsZeroDifferentFromUngraded(): void
    {
        $a = EntityId::generate();
        $s = EntityId::generate();
        self::assertSame(12000, new AssignmentScore($a, $s, 12000, 10000, 2000)->percentageHundredths());
        self::assertNull(new AssignmentScore($a, $s, null, 10000, 2000)->percentageHundredths());
        self::assertSame(0, new AssignmentScore($a, $s, 0, 10000, 2000)->percentageHundredths());
        $this->expectException(ValidationException::class);
        new AssignmentScore($a, $s, 10001, 10000);
    }

    public static function invalidLimits(): array
    {
        return [[0, 100], [100, -1], [1000000, 1], [1000001, 0], [1, 1000000]];
    }

    #[DataProvider("invalidLimits")]
    public function testCombinedLimitsRemainBounded(int $possible, int $bonus): void
    {
        $this->expectException(ValidationException::class);
        new AssignmentPoints($possible, $bonus);
    }

    public function testUnapprovedOrExcessBonusScoresCannotEnterACalculation(): void
    {
        $id = EntityId::generate()->value;
        $limits = [[], ["bonus_hundredths" => 100], ["bonus_hundredths" => null], ["bonus_hundredths" => "1000"]];
        foreach ($limits as $limit) {
            try {
                new GradebookCalculation()->summarize([
                    ["assignment_id" => $id, "possible_hundredths" => 1000, "earned_hundredths" => 1200] +
                    $limit,
                ]);
                self::fail("An invalid bonus score was included.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDroppedAndExcludedBonusMarksDoNotInflateCountedBonusTotals(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $c = EntityId::generate()->value;
        $scores = [
            [
                "assignment_id" => $a,
                "possible_hundredths" => 1000,
                "bonus_hundredths" => 500,
                "earned_hundredths" => 1100,
            ],
            [
                "assignment_id" => $b,
                "possible_hundredths" => 1000,
                "bonus_hundredths" => 500,
                "earned_hundredths" => 1300,
            ],
            [
                "assignment_id" => $c,
                "possible_hundredths" => 1000,
                "bonus_hundredths" => 500,
                "earned_hundredths" => 1500,
            ],
        ];
        $result = new GradebookCalculation(
            "category",
            [],
            [
                [
                    "title" => "Work",
                    "weight_hundredths" => 10000,
                    "assignments" => [$a, $b],
                    "drop_lowest" => 1,
                ],
            ],
        )->summarize($scores);
        self::assertSame(13000, $result["percent_hundredths"]);
        self::assertSame(300, $result["bonus_earned_hundredths"]);
        self::assertSame(1, $result["dropped"]);
        self::assertSame(1, $result["excluded"]);
        self::assertSame([$a], array_column($result["dropped_assignments"], "assignment_id"));
    }
}
