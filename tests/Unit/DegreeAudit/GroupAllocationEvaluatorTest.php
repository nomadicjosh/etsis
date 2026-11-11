<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use PHPUnit\Framework\TestCase;

final class GroupAllocationEvaluatorTest extends TestCase
{
    private static function id(int $id): string
    {
        return str_pad((string) $id, 26, "0", STR_PAD_LEFT);
    }
    private function group(
        int $id,
        array $courses = [1],
        int $credits = 0,
        int $count = 1,
        ?int $floor = null,
    ): array {
        return [
            "version_id" => self::id(200 + $id),
            "definition" => [
                "name" => "Fictional group " . $id,
                "requirement_type" => $credits > 0 ? "credits" : "courses",
                "min_credits_milli" => $credits,
                "min_courses" => $credits > 0 ? 0 : $count,
                "min_course_grade_points_milli" => $floor,
                "courses" => array_map(
                    fn($c) => ["course_id" => self::id($c), "version_id" => self::id(100 + $c)],
                    $courses,
                ),
                "rules" => [
                    [
                        "id" => self::id(300 + $id),
                        "mode" => "pool",
                        "minimum_courses" => 0,
                        "course_ids" => array_map(self::id(...), $courses),
                    ],
                ],
            ],
            "course_details" => array_combine(
                array_map(self::id(...), $courses),
                array_map(
                    fn($c) => ["code" => "COURSE." . $c, "title" => "Fictional course " . $c],
                    $courses,
                ),
            ),
        ];
    }
    private function requirements(array $groups, string $sharing = "one_group"): array
    {
        return [
            "definition" => [
                "policies" => [
                    "repeat_credit" => "highest_earned",
                    "repeat_gpa" => "all_eligible",
                    "transfer_overall_gpa" => "exclude",
                    "group_reuse" => $sharing,
                ],
            ],
            "grade_scale" => ["version_id" => self::id(900)],
            "group_details" => $groups,
        ];
    }
    private function effective(array $r): array
    {
        return [
            "groups" => array_map(
                fn($g) => array_intersect_key(
                    $g["definition"],
                    array_flip([
                        "min_credits_milli",
                        "min_courses",
                        "min_course_grade_points_milli",
                        "min_gpa_milli",
                    ]),
                ),
                $r["group_details"],
            ),
            "waived_course_ids" => [],
            "substitutions" => [],
            "substitution_conflicts" => [],
        ];
    }
    private function work(
        int $id,
        int $course = 1,
        int $credits = 3000,
        int $points = 4000,
        string $origin = "institutional",
    ): array {
        return [
            "id" => self::id($id),
            "course_id" => self::id($course),
            "course_code" => "COURSE." . $course,
            "course_title" => "Retained fictional course",
            "course_version_id" => self::id(100 + $course),
            "status" => "A",
            "credit_type" => $origin,
            "attempted_milli" => $credits,
            "completed_milli" => $credits,
            "quality_points_milli" => $origin === "transfer" ? 0 : intdiv($credits * $points, 1000),
            "grade_recorded" => true,
            "completed_on" => null,
            "grade" => [
                "id" => self::id(900 + $id),
                "code" => "P",
                "points_milli" => $points,
                "passes" => true,
                "counts_gpa" => $origin !== "transfer",
                "scale_id" => self::id(899),
                "scale_version_id" => self::id(900),
            ],
        ];
    }
    private function evaluator(int $steps = 100000): GroupAllocationEvaluator
    {
        return new GroupAllocationEvaluator(
            new CourseMatchEvaluator(),
            new AcademicPolicyEvaluator(),
            new CourseAllocationPlanner($steps),
        );
    }
    public function testGroupGradeEligibilityPrecedesRepeatCreditRankingAndCatalogCreditsAreNotUsed(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10, credits: 3000, floor: 2000)]);
        $result = $this->evaluator()->evaluate(
            [$this->work(1001, credits: 4000, points: 1000), $this->work(1002, points: 3000)],
            $r,
            $this->effective($r),
        );
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame([[self::id(1002)]], $result["groups"][0]["placements"][0]["work_options"]);
        self::assertContains("insufficient_grade", $result["records"][0]["statuses"]);
    }
    public function testSharingAndTargetCollisionsAreSolvedAcrossAllGroupsWithoutPartialPlacements(): void
    {
        foreach (["one_group" => "infeasible", "multiple_groups" => "feasible"] as $sharing => $expected) {
            $r = $this->requirements(
                [self::id(10) => $this->group(10), self::id(11) => $this->group(11)],
                $sharing,
            );
            $result = $this->evaluator()->evaluate([$this->work(1001)], $r, $this->effective($r));
            self::assertSame($expected, $result["outcome"]);
            self::assertCount($sharing === "one_group" ? 0 : 2, $result["records"][0]["uses"]);
            self::assertCount($sharing === "one_group" ? 0 : 1, $result["groups"][0]["placements"]);
        }
    }
    public function testWaiverRemovesNamedObligationButCreatesNeitherCreditsNorQuantityReduction(): void
    {
        $group = $this->group(10, credits: 3000);
        $group["definition"]["rules"][0]["mode"] = "all";
        $group["definition"]["rules"][0]["minimum_courses"] = 1;
        $r = $this->requirements([self::id(10) => $group]);
        $e = $this->effective($r);
        $e["waived_course_ids"] = [self::id(1)];
        self::assertSame("infeasible", $this->evaluator()->evaluate([], $r, $e)["outcome"]);
        // Actual earned work for a waived course can still meet the unchanged quantity minimum.
        self::assertSame("feasible", $this->evaluator()->evaluate([$this->work(1001)], $r, $e)["outcome"]);
        $e["groups"][self::id(10)]["min_credits_milli"] = 0;
        $reduced = $this->evaluator()->evaluate([], $r, $e);
        self::assertSame("feasible", $reduced["outcome"]);
        self::assertSame([], $reduced["groups"][0]["placements"]);
    }
    public function testApprovedAlternativeRetainsTheRequiredTargetAndCannotCountItTwice(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10)]);
        $e = $this->effective($r);
        $e["substitutions"][self::id(1)] = ["course_id" => self::id(2), "version_id" => self::id(102)];
        $result = $this->evaluator()->evaluate([$this->work(1002, course: 2)], $r, $e);
        self::assertSame("feasible", $result["outcome"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertTrue($p["via_substitution"]);
        self::assertSame(self::id(1), $p["target_course_id"]);
        self::assertSame(self::id(2), $p["course_id"]);
        $e["groups"][self::id(10)]["min_courses"] = 2;
        $result = $this->evaluator()->evaluate([$this->work(1001), $this->work(1002, course: 2)], $r, $e);
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }
    public function testUnknownOrDifferentEvidenceAndConflictingApprovalsNeverProducePlacements(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10, floor: 2000)]);
        foreach (["grade", "course_version_id", "scale"] as $unknown) {
            $w = $this->work(1001);
            if ($unknown === "scale") {
                $w["grade"]["scale_version_id"] = self::id(999);
            } else {
                $w[$unknown] = null;
            }
            $result = $this->evaluator()->evaluate([$w], $r, $this->effective($r));
            self::assertSame("needs_review", $result["outcome"]);
            self::assertSame([], $result["groups"][0]["placements"]);
        }
        $e = $this->effective($r);
        $e["substitution_conflicts"][self::id(1)] = "Fictional overlapping approvals.";
        self::assertSame("needs_review", $this->evaluator()->evaluate([], $r, $e)["outcome"]);
    }
    public function testTiedCreditEvidenceRemainsAlternativesWithoutChoosingAnInstitutionalWinner(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10)]);
        $result = $this->evaluator()->evaluate(
            [$this->work(1001), $this->work(1002, origin: "transfer")],
            $r,
            $this->effective($r),
        );
        $placement = $result["groups"][0]["placements"][0];
        self::assertSame(3000, $placement["credits_milli"]);
        self::assertEqualsCanonicalizing([[self::id(1001)], [self::id(1002)]], $placement["work_options"]);
        self::assertTrue($result["records"][0]["uses"][0]["alternative"]);
        self::assertTrue($result["records"][1]["uses"][0]["alternative"]);
    }
    public function testAllEligibleRepeatsRemainOneCourseForCourseCountsAndUseActualAwardAmounts(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10, count: 2)]);
        $r["definition"]["policies"]["repeat_credit"] = "all_eligible";
        $work = [$this->work(1001, credits: 1500), $this->work(1002, credits: 2500)];
        self::assertSame(
            "infeasible",
            $this->evaluator()->evaluate($work, $r, $this->effective($r))["outcome"],
        );
        $e = $this->effective($r);
        $e["groups"][self::id(10)]["min_courses"] = 1;
        $result = $this->evaluator()->evaluate($work, $r, $e);
        self::assertSame(4000, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame(
            [[self::id(1001), self::id(1002)]],
            $result["groups"][0]["placements"][0]["work_options"],
        );
    }
    public function testLegacyAverageGroupGpaIsExplicitlyUnsupportedAndInterruptedSearchIsNotFailure(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10)]);
        $r["group_details"][self::id(10)]["definition"]["min_gpa_milli"] = 2000;
        $result = $this->evaluator()->evaluate([$this->work(1001)], $r, $this->effective($r));
        self::assertSame("unsupported", $result["outcome"]);
        self::assertContains("group_average_gpa_unsupported", $result["issues"]);
        unset($r["group_details"][self::id(10)]["definition"]["min_gpa_milli"]);
        $result = $this->evaluator(1)->evaluate([$this->work(1001)], $r, $this->effective($r));
        self::assertSame("undetermined", $result["outcome"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }
    public function testAllocationNeverUsesTheTwentyFiveMatchDisplayTruncation(): void
    {
        $groups = [];
        for ($g = 10; $g < 35; $g++) {
            $groups[self::id($g)] = $this->group($g, [1, 2]);
        }
        $r = $this->requirements($groups, "multiple_groups");
        $e = $this->effective($r);
        $e["substitutions"][self::id(2)] = ["course_id" => self::id(1), "version_id" => self::id(101)];
        $work = [$this->work(1001)];
        self::assertCount(
            50,
            new CourseMatchEvaluator()->allocation($work, $r, $e)[0]["requirement_matches"],
        );
        $result = $this->evaluator()->evaluate($work, $r, $e);
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(25, $result["records"][0]["uses"]);
        $large = [];
        for ($i = 1001; $i < 1201; $i++) {
            $large[] = $this->work($i);
        }
        $this->expectException(AllocationBudgetExhaustedException::class);
        $this->evaluator()->evaluate($large, $r, $e);
    }
    public function testZeroCreditCompletionAndUnrelatedUnknownWorkDoNotInventAwardsOrBlockAValidPlacement(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10)]);
        $unknown = $this->work(1002, course: 2);
        $unknown["grade"] = null;
        $result = $this->evaluator()->evaluate(
            [$this->work(1001, credits: 0), $unknown],
            $r,
            $this->effective($r),
        );
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame(0, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame([], $result["records"][1]["uses"]);
    }
    public function testUnrecordedGradeWithExistingQualityPointsIsUnknownRatherThanCertainlyInProgress(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10)]);
        $w = $this->work(1001);
        $w["grade_recorded"] = false;
        $w["grade"] = null;
        $w["completed_milli"] = 0;
        $result = $this->evaluator()->evaluate([$w], $r, $this->effective($r));
        self::assertSame("needs_review", $result["outcome"]);
        self::assertContains("grade_unknown", $result["issues"]);
    }

}
