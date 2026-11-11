<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseMatchEvaluatorTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, "0", STR_PAD_LEFT);
    }

    private function inputs(): array
    {
        $course = self::id(1);
        $version = self::id(2);
        $scale = self::id(3);
        $group = self::id(4);
        $grade = [
            "points_milli" => 2800,
            "passes" => true,
            "counts_gpa" => true,
            "scale_version_id" => $scale,
        ];
        $work = [
            "id" => self::id(5),
            "course_id" => $course,
            "course_version_id" => $version,
            "status" => "A",
            "grade_recorded" => true,
            "grade" => $grade,
            "attempted_milli" => 3000,
            "completed_milli" => 3000,
            "quality_points_milli" => 8400,
        ];
        $requirements = [
            "grade_scale" => ["version_id" => $scale, "maximum_milli" => 5000],
            "definition" => ["policies" => ["group_reuse" => "one_group"]],
            "group_details" => [
                $group => [
                    "version_id" => self::id(6),
                    "definition" => [
                        "name" => "General education",
                        "min_course_grade_points_milli" => 2700,
                        "courses" => [["course_id" => $course, "version_id" => $version]],
                        "rules" => [["mode" => "all", "course_ids" => [$course]]],
                    ],
                    "course_details" => [$course => ["code" => "MATH.101", "title" => "Retained algebra"]],
                ],
            ],
        ];
        $effective = [
            "groups" => [],
            "waived_course_ids" => [],
            "substitutions" => [],
            "substitution_conflicts" => [],
        ];
        return [$work, $requirements, $effective];
    }

    private function first(array $work, array $requirements, array $effective): array
    {
        return new CourseMatchEvaluator()->page(
            [$work],
            $requirements,
            $effective,
        )[0]["requirement_matches"][0];
    }

    public function testInstitutionalPointsUseTheRetainedScaleAndNotWeightedQualityPoints(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        self::assertSame("candidate", $this->first($work, $requirements, $effective)["status"]);
        $work["grade"]["points_milli"] = 2600;
        $work["quality_points_milli"] = 999999;
        self::assertSame("insufficient_grade", $this->first($work, $requirements, $effective)["status"]);
        $effective["groups"][self::id(4)]["min_course_grade_points_milli"] = 2500;
        self::assertSame("candidate", $this->first($work, $requirements, $effective)["status"]);
    }

    public function testPlacementPreferenceExplainsRestrictionButCannotOverrideTheChosenGroupsEligibility(): void
    {
        [$work, $r, $e] = $this->inputs();
        $e["placements"][$work["course_id"]] = ["group_id" => self::id(99)];
        self::assertSame("placement_restricted", $this->first($work, $r, $e)["status"]);
        $e["placements"][$work["course_id"]]["group_id"] = self::id(4);
        self::assertSame("candidate", $this->first($work, $r, $e)["status"]);
        $work["grade"]["points_milli"] = 2000;
        self::assertSame("insufficient_grade", $this->first($work, $r, $e)["status"]);
        $work["course_version_id"] = self::id(77);
        self::assertSame("course_version_differs", $this->first($work, $r, $e)["status"]);
    }
    public static function states(): array
    {
        return [
            "voided" => [["status" => "V", "grade" => null], "voided"],
            "late drop" => [["status" => "W", "grade" => null], "dropped"],
            "removed early drop" => [["status" => "D"], "dropped"],
            "ungraded" => [
                ["grade_recorded" => false, "completed_milli" => 0, "grade" => null],
                "in_progress",
            ],
            "unknown prior grade" => [["grade" => null], "grade_unknown"],
            "no earned credit" => [["completed_milli" => 0], "no_credit"],
            "no course publication" => [["course_version_id" => null], "course_version_unknown"],
            "different course publication" => [
                ["course_version_id" => self::id(99)],
                "course_version_differs",
            ],
        ];
    }

    #[DataProvider("states")]
    public function testRecordsExplainWhyTheyCannotYetBeCandidates(array $changes, string $expected): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $match = $this->first(array_replace($work, $changes), $requirements, $effective);
        self::assertSame($expected, $match["status"]);
        self::assertNotEmpty($match["explanation"]);
    }

    public function testGradeDefinitionsAndScalesAreNeverGuessedOrConverted(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $work["grade"]["passes"] = false;
        self::assertSame("not_passing", $this->first($work, $requirements, $effective)["status"]);
        $work["grade"]["passes"] = true;
        $work["grade"]["scale_version_id"] = self::id(99);
        self::assertSame("scale_differs", $this->first($work, $requirements, $effective)["status"]);
        $work["grade"]["scale_version_id"] = null;
        self::assertSame("scale_unknown", $this->first($work, $requirements, $effective)["status"]);
        $work["grade"]["scale_version_id"] = self::id(3);
        $requirements["grade_scale"] = null;
        self::assertSame("scale_unknown", $this->first($work, $requirements, $effective)["status"]);
        $requirements["group_details"][self::id(4)]["definition"]["min_course_grade_points_milli"] = null;
        self::assertSame("candidate", $this->first($work, $requirements, $effective)["status"]);
    }

    public function testWaiversLeaveEarnedWorkUnchangedAndSubstitutionsKeepTheExactApprovedVersion(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $evaluator = new CourseMatchEvaluator();
        $effective["waived_course_ids"] = [$work["course_id"]];
        $page = $evaluator->page([$work], $requirements, $effective);
        self::assertSame("waived", $page[0]["requirement_matches"][0]["status"]);
        self::assertSame($work, array_intersect_key($page[0], $work));
        $effective["waived_course_ids"] = [];
        $effective["substitutions"][$work["course_id"]] = [
            "course_id" => self::id(20),
            "version_id" => self::id(21),
        ];
        $work["course_id"] = self::id(20);
        $work["course_version_id"] = self::id(21);
        $match = $this->first($work, $requirements, $effective);
        self::assertTrue($match["via_substitution"]);
        self::assertSame(self::id(1), $match["required_course_id"]);
        self::assertSame("candidate", $match["status"]);
        $work["course_version_id"] = self::id(22);
        self::assertSame("course_version_differs", $this->first($work, $requirements, $effective)["status"]);
    }

    public function testConflictsRemainVisibleAndCourseCodesCannotMatchAnotherIdentity(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $effective["substitution_conflicts"][$work["course_id"]] = "Needs review";
        self::assertSame("approval_conflict", $this->first($work, $requirements, $effective)["status"]);
        $work["course_id"] = self::id(99);
        $work["course_code"] = "MATH.101";
        $page = new CourseMatchEvaluator()->page([$work], $requirements, $effective);
        self::assertSame([], $page[0]["requirement_matches"]);
    }

    public function testOverlappingCandidatesAreNotGreedilyAllocatedAndOutputIsBounded(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $group = $requirements["group_details"][self::id(4)];
        // Two target courses per group may both use this one approved alternative.
        $other = self::id(8);
        $group["definition"]["courses"][] = ["course_id" => $other, "version_id" => self::id(9)];
        $group["definition"]["rules"][] = ["mode" => "pool", "course_ids" => [$other]];
        $group["course_details"][$other] = ["code" => "ENG.101", "title" => "Retained English"];
        $effective["substitutions"][$other] = [
            "course_id" => $work["course_id"],
            "version_id" => $work["course_version_id"],
        ];
        $requirements["group_details"] = [];
        for ($i = 10; $i < 35; $i++) {
            $requirements["group_details"][self::id($i)] = $group;
        }
        $evaluator = new CourseMatchEvaluator();
        $first = $evaluator->page([$work], $requirements, $effective);
        self::assertCount(25, $first[0]["requirement_matches"]);
        self::assertTrue($first[0]["more_requirement_matches"]);
        self::assertSame($first, $evaluator->page([$work], $requirements, $effective));
        self::assertSame(3000, $first[0]["completed_milli"]);
        self::assertArrayNotHasKey("applied_credits_milli", $first[0]);
    }

    public function testRecheckingAnEnrichedPageReplacesEarlierExplanations(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $evaluator = new CourseMatchEvaluator();
        $first = $evaluator->page([$work], $requirements, $effective);
        $effective["groups"][self::id(4)]["min_course_grade_points_milli"] = 3000;
        $again = $evaluator->page($first, $requirements, $effective);
        self::assertSame("insufficient_grade", $again[0]["requirement_matches"][0]["status"]);
    }

    public function testACompletedZeroCreditCourseCanBeACandidateWithoutInventingCredits(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $work["attempted_milli"] = 0;
        $work["completed_milli"] = 0;
        $work["quality_points_milli"] = 0;
        $page = new CourseMatchEvaluator()->page([$work], $requirements, $effective);
        self::assertSame("candidate", $page[0]["requirement_matches"][0]["status"]);
        self::assertSame(0, $page[0]["completed_milli"]);
    }

    public function testAnExplicitZeroGradeFloorNeedsPassingFactsButNoScaleConversion(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $effective["groups"][self::id(4)]["min_course_grade_points_milli"] = 0;
        $work["grade"]["scale_version_id"] = null;
        self::assertSame("candidate", $this->first($work, $requirements, $effective)["status"]);
        $work["grade"]["passes"] = false;
        self::assertSame("not_passing", $this->first($work, $requirements, $effective)["status"]);
    }

    public function testAnUnboundedPageIsRejected(): void
    {
        [$work, $requirements, $effective] = $this->inputs();
        $this->expectException(InvalidArgumentException::class);
        new CourseMatchEvaluator()->page(array_fill(0, 21, $work), $requirements, $effective);
    }
}
