<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementGradeFloorTest extends TestCase
{
    private function definition(string $mode = "credits"): array
    {
        $d = CourseGroupDefinitionV2::blank("Fictional mixed coursework")->definition;
        $course = str_repeat("0", 25) . "1";
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [["course_id" => $course, "version_id" => str_repeat("0", 25) . "2"]];
        $d["rules"] = [
            [
                "id" => str_repeat("0", 25) . "3",
                "mode" => $mode,
                "minimum_courses" => in_array($mode, ["all", "choose"], true) ? 1 : 0,
                "course_ids" => [$course],
                "min_course_grade_points_milli" => 2675,
            ],
        ];
        if ($mode === "credits") {
            $d["rules"][0]["minimum_credits_milli"] = 3000;
        }
        return $d;
    }

    public static function modes(): array
    {
        return [["all"], ["choose"], ["credits"], ["pool"]];
    }

    #[DataProvider("modes")]
    public function testEveryCardPublishesItsOwnFloorWithoutAddingFieldsToEarlierDefinitions(
        string $mode,
    ): void {
        $d = $this->definition($mode);
        foreach ([null, 0, 2675, 4000] as $floor) {
            $d["rules"][0]["min_course_grade_points_milli"] = $floor;
            $value = new CourseGroupDefinitionV2($d);
            $value->assertPublishable();
            self::assertSame($d, $value->definition);
            if ($floor > 0) {
                $plan = CourseGroupPlan::forDefinition($value, [
                    $d["courses"][0]["course_id"] => ["credits_milli" => 3000],
                ]);
                self::assertContains(
                    "Catalog totals do not check per-card grade minima. Run a student test.",
                    $plan->warnings,
                );
            }
        }
        unset($d["rules"][0]["min_course_grade_points_milli"]);
        self::assertSame($d, new CourseGroupDefinitionV2($d)->definition);
    }

    public static function invalidFloors(): array
    {
        return [[false], [true], ["3000"], [3.0], [[]], [-1], [4001], [10001]];
    }

    #[DataProvider("invalidFloors")]
    public function testMalformedOrAboveScaleFloorsCannotEnterADraft(mixed $floor): void
    {
        $d = $this->definition();
        $d["rules"][0]["min_course_grade_points_milli"] = $floor;
        $this->expectException(InvalidArgumentException::class);
        new CourseGroupDefinitionV2($d);
    }
}
