<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class GradingScaleValidationTest extends TestCase
{
    private function enteredScale(): array
    {
        $rows = [
            ["A+", 97, 100, 4000],
            ["A", 93, 96, 4000],
            ["A-", 90, 92, 3700],
            ["B+", 87, 89, 3300],
            ["B", 83, 86, 3000],
            ["B-", 80, 82, 2700],
            ["C+", 77, 79, 2300],
            ["C", 73, 76, 2000],
            ["C-", 70, 72, 1700],
            ["D+", 67, 69, 1300],
            ["D", 65, 66, 1000],
            ["F", 0, 65, 0],
        ];
        $grades = [];
        foreach ($rows as [$code, $lo, $hi, $points]) {
            $grades[] = [
                "code" => $code,
                "min_score_milli" => $lo * 1000,
                "max_score_milli" => $hi * 1000,
                "points_milli" => $points,
                "passes" => $code !== "F",
                "counts_gpa" => true,
            ];
        }
        return ["grades" => $grades];
    }

    public function testEnteredDAndFBoundaryNamesTheConflictingGradesAndBothFields(): void
    {
        try {
            new GradingScaleDefinition($this->enteredScale());
            self::fail("A score of 65 was assigned to two grades.");
        } catch (ValidationException $e) {
            self::assertSame(
                ["definition.grades.11.max_score_milli", "definition.grades.10.min_score_milli"],
                array_column($e->errors, "field"),
            );
            self::assertStringContainsString("Grades F and D both include 65%", $e->errors[0]["message"]);
            self::assertStringContainsString("both endpoints", $e->errors[0]["message"]);
        }
    }

    public function testCorrectedBoundaryRetainsEveryGradeAndAssigns65ToD(): void
    {
        $definition = $this->enteredScale();
        $definition["grades"][11]["max_score_milli"] = 64999;
        $scale = new GradingScaleDefinition($definition);
        self::assertCount(12, $scale->definition["grades"]);
        self::assertSame("D", $scale->gradeForScore(65000)["code"]);
        self::assertSame("F", $scale->gradeForScore(64999)["code"]);
        self::assertSame(1000, $scale->gradeForScore(65000)["points_milli"]);
        self::assertFalse($scale->gradeForScore(64999)["passes"]);
        self::assertTrue($scale->gradeForScore(64999)["counts_gpa"]);
        self::assertSame(4000, $scale->maximum());
    }

    public function testTheInstitutionCanExplicitlyAssignTheBoundaryToFInstead(): void
    {
        $definition = $this->enteredScale();
        $definition["grades"] = array_reverse($definition["grades"]);
        try {
            new GradingScaleDefinition($definition);
            self::fail("The opposite row ordering hid the overlap.");
        } catch (ValidationException $e) {
            self::assertSame(
                ["definition.grades.1.min_score_milli", "definition.grades.0.max_score_milli"],
                array_column($e->errors, "field"),
            );
        }
        $definition["grades"][1]["min_score_milli"] = 65001;
        $scale = new GradingScaleDefinition($definition);
        self::assertSame("F", $scale->gradeForScore(65000)["code"]);
        self::assertSame("D", $scale->gradeForScore(65001)["code"]);
    }
}
