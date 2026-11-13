<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\RuleAllocationCompiler;
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
    private function ruleGroup(int $id, int $minimum = 3000, int $cards = 1): array
    {
        $g = $this->group($id, [], $minimum);
        $g["definition"]["rules"] = [];
        for ($i = 0; $i < $cards; $i++) {
            $g["definition"]["rules"][] = [
                "id" => self::id(300 + $id + $i),
                "mode" => "credits",
                "minimum_courses" => 0,
                "minimum_credits_milli" => $minimum,
                "course_ids" => [],
                "eligibility" => ["id" => self::id(800 + $i)],
            ];
        }
        return $g;
    }

    /** Predicate results are supplied by the protected-reader boundary, tested separately in integration. */
    private function decisions(array $work, array $requirements, array $outcomes = []): array
    {
        $rows = [];
        foreach ($requirements["group_details"] as $group => $definition) {
            foreach ($definition["definition"]["rules"] as $rule) {
                if (!isset($rule["eligibility"])) {
                    continue;
                }
                foreach ($work as $item) {
                    $rows[] = [
                        "group_id" => $group,
                        "requirement_id" => $rule["id"],
                        "work_id" => $item["id"],
                        "outcome" => $outcomes[count($rows)] ?? "matches",
                    ];
                }
            }
        }
        return $rows;
    }

    public function testOverlappingSavedRulesUseOneAwardWithoutMultiplyingGroupCredits(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 3000, 2)]);
        $work = [$this->work(10)];
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(1, $result["groups"][0]["placements"]);
        self::assertSame(3000, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame([], $r["group_details"][self::id(1)]["definition"]["courses"]);
        self::assertSame([self::id(1)], $result["groups"][0]["rules"][0]["course_ids"]);
    }

    public function testRuleExclusionsAndGradeFloorsApplyBeforeHighestEligibleRepeatSelection(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 6000)]);
        $work = [$this->work(10), $this->work(11, credits: 6000)];
        $work[1]["course_version_id"] = self::id(500);
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, ["matches", "does_not_match"]),
        );
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["gaps"]["credit_shortfall_milli"]);
        $work[1] = $this->work(11, credits: 6000, points: 1000);
        $r["group_details"][self::id(1)]["definition"]["min_course_grade_points_milli"] = 2000;
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["gaps"]["credit_shortfall_milli"]);
        self::assertContains("insufficient_grade", $result["records"][1]["statuses"]);
    }

    public function testAllEligibleRepeatAwardsSupplyCreditsButRemainOneCourseIdentity(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 7000)]);
        $r["definition"]["policies"]["repeat_credit"] = "all_eligible";
        $work = [$this->work(10), $this->work(11, credits: 4000)];
        $work[1]["course_version_id"] = self::id(500);
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame(7000, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame(
            [[self::id(10), self::id(11)]],
            $result["groups"][0]["placements"][0]["work_options"],
        );
        $g = &$r["group_details"][self::id(1)]["definition"];
        $g["rules"][0]["mode"] = "choose";
        $g["rules"][0]["minimum_courses"] = 2;
        unset($g["rules"][0]["minimum_credits_milli"]);
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(1, $result["groups"][0]["gaps"]["rules"][0]["shortfall_courses"]);
    }

    public function testSavedRulesRespectTheRetainedSharingPolicy(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1), self::id(2) => $this->ruleGroup(2)]);
        $work = [$this->work(10)];
        $one = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("infeasible", $one["outcome"]);
        self::assertSame([], $one["groups"][0]["placements"]);
        $r["definition"]["policies"]["group_reuse"] = "multiple_groups";
        $many = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("feasible", $many["outcome"]);
        self::assertCount(1, $many["groups"][0]["placements"]);
        self::assertCount(1, $many["groups"][1]["placements"]);
    }

    public function testHighestEligibleCannotCombineDisjointRepeatMembershipsIntoCompletion(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 3000, 2)]);
        $work = [$this->work(10), $this->work(11)];
        $work[1]["course_version_id"] = self::id(500);
        $matrix = ["matches", "does_not_match", "does_not_match", "matches"];
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame([], $result["issues"]);
        self::assertSame([], $result["groups"][0]["placements"]);
        // A failed earlier award cannot lend its rule membership to a passing later award.
        $work[0] = $this->work(10, points: 0);
        $work[0]["grade"]["passes"] = false;
        $work[0]["completed_milli"] = 0;
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["gaps"]["rules"][0]["credit_shortfall_milli"]);
    }

    public function testUnknownRuleFactsDoNotBecomeAnAcademicFailureOrPoisonKnownUnusableWork(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1)]);
        $work = [$this->work(10), $this->work(11, course: 2)];
        $matrix = ["matches", "unknown"];
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("needs_review", $result["outcome"]);
        self::assertContains("rule_facts_unknown", $result["issues"]);
        self::assertNull($result["groups"][0]["gaps"]);
        $work[1] = $this->work(11, course: 2, points: 0);
        $work[1]["grade"]["passes"] = false;
        $work[1]["completed_milli"] = 0;
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("feasible", $result["outcome"]);
    }

    public function testNamedPublicationAndSavedRuleCannotUseOneCourseUnderConflictingDefinitions(): void
    {
        $g = $this->ruleGroup(1);
        $named = $this->group(1);
        $g["definition"]["courses"] = $named["definition"]["courses"];
        $g["course_details"] = $named["course_details"];
        $g["definition"]["rules"][] = [
            "id" => self::id(399),
            "mode" => "all",
            "minimum_courses" => 1,
            "course_ids" => [self::id(1)],
        ];
        $r = $this->requirements([self::id(1) => $g]);
        $work = [$this->work(10)];
        $work[0]["course_version_id"] = self::id(500);
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("needs_review", $result["outcome"]);
        self::assertContains("rule_publication_conflict", $result["issues"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testHighestMatchingAwardKeepsItsOwnAcceptedCourseLabels(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 4000)]);
        $work = [$this->work(10), $this->work(11, credits: 4000)];
        $work[1]["course_version_id"] = self::id(500);
        $work[1]["course_code"] = "UPDATED.101";
        $work[1]["course_title"] = "Later accepted title";
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame("Later accepted title", $result["groups"][0]["placements"][0]["target_title"]);
        self::assertSame("UPDATED.101", $result["groups"][0]["courses"][self::id(1)]["code"]);
        self::assertSame([[self::id(11)]], $result["groups"][0]["placements"][0]["work_options"]);
        self::assertContains("repeat_not_selected", $result["records"][0]["statuses"]);
    }

    public function testSubstitutionAndSavedRuleShareOnePlacementAndOneActualAward(): void
    {
        $g = $this->ruleGroup(1);
        $named = $this->group(1, [2]);
        $g["definition"]["courses"] = $named["definition"]["courses"];
        $g["course_details"] = $named["course_details"];
        $g["definition"]["rules"][] = [
            "id" => self::id(399),
            "mode" => "all",
            "minimum_courses" => 1,
            "course_ids" => [self::id(2)],
        ];
        $r = $this->requirements([self::id(1) => $g]);
        $effective = $this->effective($r);
        $effective["substitutions"][self::id(2)] = [
            "course_id" => self::id(1),
            "version_id" => self::id(101),
        ];
        $work = [$this->work(10)];
        $result = $this->evaluator()->evaluate($work, $r, $effective, $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame([], $result["issues"]);
        self::assertCount(1, $result["groups"][0]["placements"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertSame(self::id(1), $p["course_id"]);
        self::assertSame(self::id(2), $p["target_course_id"]);
        self::assertTrue($p["via_substitution"]);
        self::assertSame(3000, $p["credits_milli"]);
        self::assertSame([self::id(301) => 3000, self::id(399) => 3000], $p["rule_credits_milli"]);
        self::assertSame([self::id(1)], $result["groups"][0]["rules"][0]["course_ids"]);
        self::assertSame([self::id(1)], $result["groups"][0]["gaps"]["rules"][0]["eligible_course_ids"]);
    }

    private function substitutionRuleInputs(array $namedCourses = [2]): array
    {
        $g = $this->ruleGroup(1);
        $named = $this->group(1, $namedCourses);
        $g["definition"]["courses"] = $named["definition"]["courses"];
        $g["course_details"] = $named["course_details"];
        $g["definition"]["rules"][] = [
            "id" => self::id(399),
            "mode" => "all",
            "minimum_courses" => count($namedCourses),
            "course_ids" => array_map(self::id(...), $namedCourses),
        ];
        $r = $this->requirements([self::id(1) => $g]);
        $e = $this->effective($r);
        foreach ($namedCourses as $course) {
            $e["substitutions"][self::id($course)] = [
                "course_id" => self::id(1),
                "version_id" => self::id(101),
            ];
        }
        return [$r, $e];
    }

    public function testCombinedSubstitutionDoesNotCreateASecondCourseOrDoubleGroupCredits(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $work = [$this->work(10)];
        $e["groups"][self::id(1)]["min_credits_milli"] = 6000;
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["gaps"]["credit_shortfall_milli"]);
        $e["groups"][self::id(1)]["min_credits_milli"] = 3000;
        $rule = &$r["group_details"][self::id(1)]["definition"]["rules"][0];
        $rule["mode"] = "choose";
        $rule["minimum_courses"] = 2;
        unset($rule["minimum_credits_milli"]);
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        $gap = $result["groups"][0]["gaps"]["rules"][0];
        self::assertSame(1, $gap["course_upper_bound"]);
        self::assertSame(1, $gap["shortfall_courses"]);
        self::assertSame([self::id(1)], $gap["eligible_course_ids"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testOriginalCourseCannotBorrowItsSubstitutesSavedRuleMatch(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $work = [$this->work(10), $this->work(11, course: 2)];
        $e["groups"][self::id(1)]["min_credits_milli"] = 6000;
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $e,
            $this->decisions($work, $r, ["matches", "does_not_match"]),
        );
        self::assertSame("feasible", $result["outcome"]);
        $placements = $result["groups"][0]["placements"];
        self::assertCount(2, $placements);
        $original = array_values(array_filter($placements, fn($p) => $p["course_id"] === self::id(2)))[0];
        self::assertSame([self::id(399) => 3000], $original["rule_credits_milli"]);
        self::assertFalse($original["via_substitution"]);
        $matching = array_values(array_filter($placements, fn($p) => $p["course_id"] === self::id(1)))[0];
        self::assertSame([self::id(301) => 3000], $matching["rule_credits_milli"]);
        self::assertSame(self::id(1), $matching["target_course_id"]);
    }

    public function testOneSubstituteCannotFillTwoDifferentNamedRequirements(): void
    {
        [$r, $e] = $this->substitutionRuleInputs([2, 3]);
        $work = [$this->work(10)];
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        $gap = $result["groups"][0]["gaps"]["rules"][1];
        self::assertSame(2, $gap["eligible_target_count"]);
        self::assertSame(1, $gap["course_upper_bound"]);
        self::assertSame(1, $gap["shortfall_courses"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testSavedRuleCannotSupplyAnUnapprovedReplacementOrIgnoreItsExactPublication(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $work = [$this->work(10)];
        $e["substitutions"] = [];
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $result["outcome"]);
        [$r, $e] = $this->substitutionRuleInputs();
        $work[0]["course_version_id"] = self::id(500);
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("needs_review", $result["outcome"]);
        self::assertContains("course_version_differs", $result["issues"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testWaiverRemovesTheNamedObligationWhileRuleWorkSuppliesOnlyActualCredits(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $work = [$this->work(10)];
        $e["waived_course_ids"] = [self::id(2)];
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertFalse($p["via_substitution"]);
        self::assertSame(self::id(1), $p["target_course_id"]);
        self::assertSame([self::id(301) => 3000], $p["rule_credits_milli"]);
        self::assertSame(0, $result["groups"][0]["gaps"]["rules"][1]["required_courses"]);
    }

    public function testCombinedNamedAndRulePlacementStillRespectsSharingAndGradeFloors(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $work = [$this->work(10, points: 1000)];
        $r["group_details"][self::id(1)]["definition"]["min_course_grade_points_milli"] = 2000;
        $e["groups"][self::id(1)]["min_course_grade_points_milli"] = 2000;
        $low = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $low["outcome"]);
        self::assertSame(3000, $low["groups"][0]["gaps"]["credit_shortfall_milli"]);
        $work = [$this->work(10)];
        $r["group_details"][self::id(2)] = $this->ruleGroup(2);
        $e["groups"][self::id(2)] = $this->effective($r)["groups"][self::id(2)];
        $one = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("infeasible", $one["outcome"]);
        $r["definition"]["policies"]["group_reuse"] = "multiple_groups";
        $many = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r));
        self::assertSame("feasible", $many["outcome"]);
        self::assertTrue($many["groups"][0]["placements"][0]["via_substitution"]);
        self::assertCount(1, $many["groups"][0]["placements"]);
        self::assertCount(1, $many["groups"][1]["placements"]);
    }

    public function testMissingOrDuplicateCapturedRuleDecisionsCannotBecomeAnEmptyEligibleUniverse(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1)]);
        $work = [$this->work(10)];
        $rows = $this->decisions($work, $r);
        foreach ([[], array_merge($rows, $rows)] as $invalid) {
            try {
                $this->evaluator()->evaluate($work, $r, $this->effective($r), $invalid);
                self::fail("Incomplete or duplicate eligibility was accepted.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllEligibleKeepsSeparateCardCreditsAcrossDifferentRepeatMemberships(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 3000, 2)]);
        $r["definition"]["policies"]["repeat_credit"] = "all_eligible";
        $work = [$this->work(10), $this->work(11, credits: 4000)];
        $work[1]["course_version_id"] = self::id(500);
        $matrix = ["matches", "does_not_match", "does_not_match", "matches"];
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("feasible", $result["outcome"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertSame(7000, $p["credits_milli"]);
        self::assertSame([self::id(301) => 3000, self::id(302) => 4000], $p["rule_credits_milli"]);
        self::assertSame([[self::id(10), self::id(11)]], $p["work_options"]);
        $r["group_details"][self::id(1)]["definition"]["rules"][0]["minimum_credits_milli"] = 4000;
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(1000, $result["groups"][0]["gaps"]["rules"][0]["credit_shortfall_milli"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testHighestEligibleBacktracksBetweenMembershipsWithoutLendingCreditsAcrossCards(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 3000, 2)]);
        $r["group_details"][self::id(1)]["definition"]["min_credits_milli"] = 7000;
        $r["group_details"][self::id(1)]["definition"]["rules"][1]["minimum_credits_milli"] = 4000;
        $work = [$this->work(10), $this->work(11, credits: 4000), $this->work(12, course: 2)];
        $work[1]["course_version_id"] = self::id(500);
        $matrix = ["matches", "does_not_match", "matches", "does_not_match", "matches", "does_not_match"];
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(2, $result["groups"][0]["placements"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertSame([[self::id(11)]], $p["work_options"]);
        self::assertSame([self::id(302) => 4000], $p["rule_credits_milli"]);
        $reversed = $this->evaluator()->evaluate(
            array_reverse($work),
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame($result["groups"], $reversed["groups"]);
    }

    public function testEqualCreditAwardsWithDifferentMembershipsArePlacementChoicesRatherThanEquivalentEvidence(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1, 3000, 2)]);
        $work = [$this->work(10), $this->work(11), $this->work(12, course: 2)];
        $work[1]["course_version_id"] = self::id(500);
        $matrix = ["matches", "does_not_match", "matches", "does_not_match", "matches", "does_not_match"];
        $result = $this->evaluator()->evaluate(
            $work,
            $r,
            $this->effective($r),
            $this->decisions($work, $r, $matrix),
        );
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame([[self::id(11)]], $result["groups"][0]["placements"][0]["work_options"]);
        self::assertSame(
            [self::id(302) => 3000],
            $result["groups"][0]["placements"][0]["rule_credits_milli"],
        );
    }

    public function testZeroCreditMatchingAwardStillSuppliesOneCourseToAChooseCard(): void
    {
        $r = $this->requirements([self::id(1) => $this->ruleGroup(1)]);
        $g = &$r["group_details"][self::id(1)]["definition"];
        $g["requirement_type"] = "courses";
        $g["min_credits_milli"] = 0;
        $g["min_courses"] = 1;
        $g["rules"][0]["mode"] = "choose";
        $g["rules"][0]["minimum_courses"] = 1;
        unset($g["rules"][0]["minimum_credits_milli"]);
        $work = [$this->work(10, credits: 0)];
        $result = $this->evaluator()->evaluate($work, $r, $this->effective($r), $this->decisions($work, $r));
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame([self::id(301) => 0], $result["groups"][0]["placements"][0]["rule_credits_milli"]);
        self::assertSame(1, $result["groups"][0]["gaps"]["rules"][0]["eligible_target_count"]);
    }

    public function testAcceptedNamedVersionsKeepEachAwardsOwnSavedRuleMembership(): void
    {
        [$r, $e] = $this->substitutionRuleInputs();
        $g = &$r["group_details"][self::id(1)];
        $g["definition"]["courses"] = [
            [
                "course_id" => self::id(1),
                "version_id" => self::id(101),
                "accepted_version_ids" => [self::id(500)],
            ],
        ];
        $g["definition"]["rules"][1]["course_ids"] = [self::id(1)];
        $g["course_details"] = [self::id(1) => ["code" => "COURSE.1", "title" => "Required title"]];
        $r["definition"]["policies"]["repeat_credit"] = "all_eligible";
        $e["substitutions"] = [];
        $e["groups"][self::id(1)]["min_credits_milli"] = 7000;
        $work = [$this->work(10), $this->work(11, credits: 4000)];
        $work[1]["course_version_id"] = self::id(500);
        $matrix = ["matches", "does_not_match"];
        $before = json_encode($r, JSON_THROW_ON_ERROR);
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r, $matrix));
        self::assertSame($before, json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame(
            [self::id(301) => 3000, self::id(399) => 7000],
            $result["groups"][0]["placements"][0]["rule_credits_milli"],
        );
        $g["definition"]["rules"][0]["minimum_credits_milli"] = 4000;
        $result = $this->evaluator()->evaluate($work, $r, $e, $this->decisions($work, $r, $matrix));
        self::assertSame("infeasible", $result["outcome"]);
        self::assertSame(1000, $result["groups"][0]["gaps"]["rules"][0]["credit_shortfall_milli"]);
    }

    public function testNamedVersionAllowancesCannotLeakIntoAnotherCoursesSubstitution(): void
    {
        $r = $this->requirements([self::id(1) => $this->group(1)]);
        $r["group_details"][self::id(1)]["definition"]["courses"][0]["accepted_version_ids"] = [
            self::id(500),
        ];
        $e = $this->effective($r);
        $e["substitutions"][self::id(1)] = ["course_id" => self::id(2), "version_id" => self::id(102)];
        $work = $this->work(10, course: 2);
        $work["course_version_id"] = self::id(500);
        $result = $this->evaluator()->evaluate([$work], $r, $e);
        self::assertSame("needs_review", $result["outcome"]);
        self::assertContains("course_version_differs", $result["issues"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }

    public function testRuleOnlyPlacementDoesNotClaimItUsedAnUnneededNamedVersionException(): void
    {
        $r=$this->requirements([self::id(1)=>$this->ruleGroup(1,4000)]);
        $e=$this->effective($r);$e['accepted_course_versions'][self::id(1)][self::id(500)]=true;
        $work=[$this->work(10),$this->work(11,credits:4000)];$work[1]['course_version_id']=self::id(500);
        $result=$this->evaluator()->evaluate($work,$r,$e,$this->decisions($work,$r));
        self::assertSame('feasible',$result['outcome']);
        self::assertFalse($result['groups'][0]['placements'][0]['via_substitution']);
    }

    private function evaluator(int $steps = 100000): GroupAllocationEvaluator
    {
        return new GroupAllocationEvaluator(
            new CourseMatchEvaluator(),
            new AcademicPolicyEvaluator(),
            new CourseAllocationPlanner($steps),
            new AllocationGapAnalyzer(),
            new RuleAllocationCompiler(new CourseMatchEvaluator()),
        );
    }
    public function testApprovedPreferenceRestrictsTheCourseWithoutBypassingEligibilityOrOtherGroupSharing(): void
    {
        foreach (["one_group", "multiple_groups"] as $sharing) {
            $r = $this->requirements([self::id(10) => $this->group(10, [1, 2]), self::id(11) => $this->group(11, [1, 2])], $sharing);
            $e = $this->effective($r);
            $e["placements"][self::id(1)] = ["group_id" => self::id(11)];
            $work = [$this->work(1001), $this->work(1002, 2)];
            $result = $this->evaluator()->evaluate($work, $r, $e);
            self::assertSame("feasible", $result["outcome"]);
            $record = array_column($result["records"], null, "id")[self::id(1001)];
            self::assertSame(self::id(11), $record["preferred_group_id"]);
            foreach ($record["uses"] as $use) self::assertSame(self::id(11), $use["group_id"]);
            self::assertContains("placement_restricted", $record["statuses"]);
            $work[0]["course_version_id"] = self::id(999);
            self::assertSame("needs_review", $this->evaluator()->evaluate($work, $r, $e)["outcome"]);
        }
    }
    public function testPreferenceDoesNotChooseARetakeOrTurnAFailingGradeIntoEarnedCredit(): void
    {
        $r = $this->requirements([self::id(10) => $this->group(10, [1], floor: 3000), self::id(11) => $this->group(11, [1], count: 0)], "multiple_groups");
        $e = $this->effective($r);
        $e["placements"][self::id(1)] = ["group_id" => self::id(10)];
        $low = $this->work(1001, credits: 4000, points: 2000);
        $passing = $this->work(1002, credits: 3000, points: 4000);
        $result = $this->evaluator()->evaluate([$low, $passing], $r, $e);
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame([[self::id(1002)]], $result["groups"][0]["placements"][0]["work_options"]);
        self::assertSame("infeasible", $this->evaluator()->evaluate([$low], $r, $e)["outcome"]);
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
        self::assertSame(3000, $result["groups"][0]["gaps"]["credit_upper_bound_milli"]);
        $e = $this->effective($r);
        $e["groups"][self::id(10)]["min_credits_milli"] = 3500;
        $short = $this->evaluator()->evaluate(
            [$this->work(1001, credits: 4000, points: 1000), $this->work(1002, points: 3000)],
            $r,
            $e,
        );
        self::assertSame("infeasible", $short["outcome"]);
        self::assertSame(500, $short["groups"][0]["gaps"]["credit_shortfall_milli"]);
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
            self::assertFalse($result["groups"][0]["gaps"]["has_shortfall"]);
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
            self::assertNull($result["groups"][0]["gaps"]);
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
        self::assertNull($result["groups"][0]["gaps"]);
        unset($r["group_details"][self::id(10)]["definition"]["min_gpa_milli"]);
        $result = $this->evaluator(1)->evaluate([$this->work(1001)], $r, $this->effective($r));
        self::assertSame("undetermined", $result["outcome"]);
        self::assertSame([], $result["groups"][0]["placements"]);
        self::assertFalse($result["groups"][0]["gaps"]["has_shortfall"]);
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
