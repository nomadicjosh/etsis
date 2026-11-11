<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\ValueObject\AllocationOutcome as Outcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse as Reuse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseAllocationPlannerTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, "0", STR_PAD_LEFT);
    }
    private function rule(string $mode, array $targets, ?int $minimum = null, int $id = 100): array
    {
        return [
            "id" => self::id($id),
            "mode" => $mode,
            "minimum_courses" => $minimum ?? ($mode === "all" ? count($targets) : 0),
            "course_ids" => $targets,
        ];
    }
    private function group(
        int $id,
        int $credits = 0,
        int $count = 1,
        ?array $rules = null,
        array $waived = [],
    ): array {
        return [
            "id" => self::id($id),
            "min_credits_milli" => $credits,
            "min_courses" => $count,
            "rules" => $rules ?? [$this->rule("pool", [self::id(1), self::id(2), self::id(3)])],
            "waived_course_ids" => $waived,
        ];
    }
    private function choice(int $group, int $target, int $credits = 3000, int $work = 1001): array
    {
        return [
            "group_id" => self::id($group),
            "target_course_id" => self::id($target),
            "credits_milli" => $credits,
            "work_ids" => [self::id($work)],
        ];
    }
    private function course(int $id, array $choices): array
    {
        return ["course_id" => self::id($id), "choices" => $choices];
    }
    private function problem(array $groups, array $courses, Reuse $reuse): AllocationProblem
    {
        $waived = [];
        foreach ($groups as &$group) {
            array_push($waived, ...$group["waived_course_ids"]);
            unset($group["waived_course_ids"]);
        }
        unset($group);
        return new AllocationProblem($groups, $courses, $reuse, array_values(array_unique($waived)));
    }

    private function solve(
        array $groups,
        array $courses,
        Reuse $reuse = Reuse::OneGroup,
    ): \Domain\DegreeAudit\ValueObject\AllocationResult {
        return new CourseAllocationPlanner()->plan($this->problem($groups, $courses, $reuse));
    }

    public function testItBacktracksWhenTheFirstNamedCoursePlacementBlocksAnotherGroup(): void
    {
        $a = self::id(1);
        $b = self::id(2);
        $groups = [
            $this->group(10, rules: [$this->rule("all", [$a])]),
            $this->group(11, rules: [$this->rule("all", [$b]), $this->rule("pool", [self::id(3)], id: 101)]),
        ];
        $courses = [
            $this->course(1, [$this->choice(10, 1), $this->choice(11, 2)]),
            $this->course(2, [$this->choice(10, 1, work: 1002), $this->choice(11, 3, work: 1002)]),
        ];
        $result = $this->solve($groups, $courses);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertSame([self::id(2), self::id(1)], array_column($result->placements, "course_id"));
        self::assertSame([self::id(10), self::id(11)], array_column($result->placements, "group_id"));
    }

    public function testItBacktracksForActualFractionalAwardedCreditsInsteadOfUsingCourseCounts(): void
    {
        $groups = [$this->group(10, 3125, 0), $this->group(11, 4250, 0)];
        $courses = [
            $this->course(1, [$this->choice(10, 1, 3125), $this->choice(11, 1, 4250, 1101)]),
            $this->course(2, [$this->choice(10, 2, 3125, 1002), $this->choice(11, 2, 2000, 1102)]),
        ];
        $result = $this->solve($groups, $courses);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertSame([3125, 4250], array_column($result->placements, "credits_milli"));
        self::assertSame([self::id(2), self::id(1)], array_column($result->placements, "course_id"));
    }

    public function testSharingChangesGroupPlacementWithoutCreatingAnOverallCreditTotal(): void
    {
        $groups = [$this->group(10), $this->group(11)];
        $courses = [$this->course(1, [$this->choice(10, 1), $this->choice(11, 1)])];
        self::assertSame(Outcome::Infeasible, $this->solve($groups, $courses)->outcome);
        $shared = $this->solve($groups, $courses, Reuse::MultipleGroups);
        self::assertSame(Outcome::Feasible, $shared->outcome);
        self::assertCount(2, $shared->placements);
        self::assertSame(
            [self::id(1001)],
            array_values(array_unique(array_merge(...array_column($shared->placements, "work_ids")))),
        );
        self::assertObjectNotHasProperty("overall_credits_milli", $shared);
    }

    public function testRepeatedCardsCountOneActualCourseOnceAndMayCoverBothRules(): void
    {
        $rules = [
            $this->rule("all", [self::id(1)]),
            $this->rule("choose", [self::id(1), self::id(2)], 1, 101),
        ];
        $course = $this->course(1, [$this->choice(10, 1)]);
        $one = $this->solve([$this->group(10, rules: $rules)], [$course]);
        self::assertSame(Outcome::Feasible, $one->outcome);
        self::assertCount(1, $one->placements);
        self::assertSame(
            Outcome::Infeasible,
            $this->solve([$this->group(10, count: 2, rules: $rules)], [$course])->outcome,
        );
    }

    public function testOneAlternativeCannotStandForTwoDistinctRequiredCoursesInTheSameGroup(): void
    {
        $group = $this->group(10, rules: [$this->rule("all", [self::id(1), self::id(2)])]);
        $course = $this->course(3, [$this->choice(10, 1), $this->choice(10, 2)]);
        self::assertSame(Outcome::Infeasible, $this->solve([$group], [$course])->outcome);
        self::assertSame(
            Outcome::Infeasible,
            $this->solve([$group], [$course], Reuse::MultipleGroups)->outcome,
        );
    }

    public function testOriginalAndAlternativeCannotInflateOneTargetIntoTwoCourses(): void
    {
        $group = $this->group(10, count: 2, rules: [$this->rule("all", [self::id(1)])]);
        $courses = [
            $this->course(1, [$this->choice(10, 1)]),
            $this->course(2, [$this->choice(10, 1, work: 1002)]),
        ];
        self::assertSame(Outcome::Infeasible, $this->solve([$group], $courses)->outcome);
    }

    public function testWaiverRemovesAnAllRequirementWithoutCreatingCreditsOrLoweringChooseCounts(): void
    {
        $waived = [self::id(1)];
        $all = [$this->rule("all", $waived)];
        $zero = $this->solve([$this->group(10, count: 0, rules: $all, waived: $waived)], []);
        self::assertSame(Outcome::Feasible, $zero->outcome);
        self::assertSame([], $zero->placements);
        self::assertSame(
            Outcome::Infeasible,
            $this->solve([$this->group(10, 3000, 0, $all, $waived)], [])->outcome,
        );
        $choose = [$this->rule("choose", [self::id(1), self::id(2)], 1)];
        self::assertSame(
            Outcome::Infeasible,
            $this->solve([$this->group(10, count: 0, rules: $choose, waived: $waived)], [])->outcome,
        );
    }

    public function testActualWaivedWorkAndZeroCreditCoursesStillHaveTheirRealQuantity(): void
    {
        $group = $this->group(
            10,
            count: 1,
            rules: [$this->rule("all", [self::id(1)])],
            waived: [self::id(1)],
        );
        $result = $this->solve([$group], [$this->course(1, [$this->choice(10, 1, 0)])]);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(1, $result->placements);
        self::assertSame(0, $result->placements[0]["credits_milli"]);
    }

    public function testUnlistedAndUnusedCoursesNeverBecomeSyntheticPlacements(): void
    {
        $courses = [
            $this->course(1, []),
            $this->course(2, [$this->choice(10, 2, work: 1002)]),
            $this->course(3, [$this->choice(10, 3, work: 1003)]),
        ];
        $result = $this->solve([$this->group(10)], $courses);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(1, $result->placements);
        self::assertSame(self::id(2), $result->placements[0]["course_id"]);
    }

    public function testNormalizedInputOrderCannotChangeTheChosenPlanOrWorkBudget(): void
    {
        $groups = [$this->group(10), $this->group(11)];
        $courses = [
            $this->course(1, [$this->choice(10, 1), $this->choice(11, 1)]),
            $this->course(2, [$this->choice(10, 2, work: 1002), $this->choice(11, 2, work: 1002)]),
        ];
        $expected = $this->solve($groups, $courses);
        $courses = array_reverse($courses);
        foreach ($courses as &$course) {
            $course["choices"] = array_reverse($course["choices"]);
        }
        unset($course);
        self::assertEquals($expected, $this->solve(array_reverse($groups), $courses));
    }

    public function testExhaustedSearchNeverReturnsAPartialPlanOrAnAcademicFailure(): void
    {
        $problem = $this->problem(
            [$this->group(10)],
            [$this->course(1, [$this->choice(10, 1)])],
            Reuse::OneGroup,
        );
        $planner = new CourseAllocationPlanner(1);
        $first = $planner->plan($problem);
        self::assertSame(Outcome::Undetermined, $first->outcome);
        self::assertSame([], $first->placements);
        self::assertSame(1, $first->steps);
        self::assertEquals($first, $planner->plan($problem));
    }

    public function testAWorkLimitAppliesAcrossIndependentSharedGroups(): void
    {
        $group = $this->group(10);
        $course = $this->course(1, [$this->choice(10, 1)]);
        $single = $this->solve([$group], [$course]);
        $course["choices"][] = $this->choice(11, 1);
        $problem = $this->problem([$group, $this->group(11)], [$course], Reuse::MultipleGroups);
        $result = new CourseAllocationPlanner($single->steps)->plan($problem);
        self::assertSame(Outcome::Undetermined, $result->outcome);
        self::assertSame($single->steps, $result->steps);
        self::assertSame([], $result->placements);
    }

    public function testTwoHundredUnambiguousCoursesRemainWithinTheDefaultBudget(): void
    {
        $ids = array_map(self::id(...), range(1, 200));
        $rules = [];
        foreach (array_chunk($ids, 50) as $i => $chunk) {
            $rules[] = $this->rule("all", $chunk, id: 500 + $i);
        }
        $courses = [];
        foreach (range(1, 200) as $i) {
            $courses[] = $this->course($i, [$this->choice(1000, $i, 3000, 2000 + $i)]);
        }
        $result = $this->solve([$this->group(1000, 600000, 200, $rules)], $courses);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(200, $result->placements);
        self::assertLessThanOrEqual(100000, $result->steps);
    }

    public static function graphs(): array
    {
        $cases = [];
        foreach (range(0, 63) as $edges) {
            foreach ([Reuse::OneGroup, Reuse::MultipleGroups] as $reuse) {
                $cases[$edges . ":" . $reuse->value] = [$edges, $reuse];
            }
        }
        return $cases;
    }

    #[DataProvider("graphs")]
    public function testSmallWeightedGraphsAgreeWithIndependentExhaustiveEnumeration(
        int $edges,
        Reuse $reuse,
    ): void {
        $groups = [$this->group(10, 3000, 1), $this->group(11, 4000, 1)];
        $weights = [1000, 3000, 4000];
        $courses = [];
        for ($i = 0; $i < 3; $i++) {
            $choices = [];
            for ($g = 0; $g < 2; $g++) {
                if (($edges & (1 << ($i * 2 + $g))) !== 0) {
                    $choices[] = $this->choice(10 + $g, 1 + $i, $weights[$i], 1001 + $i);
                }
            }
            $courses[] = $this->course(1 + $i, $choices);
        }
        $expected = false;
        // Independent finite enumeration: each course may go nowhere, group 1, group 2, or both.
        for ($a = 0; $a < 4; $a++) {
            for ($b = 0; $b < 4; $b++) {
                for ($c = 0; $c < 4; $c++) {
                    $amounts = [0, 0];
                    $valid = true;
                    foreach ([$a, $b, $c] as $i => $mask) {
                        if ($reuse === Reuse::OneGroup && $mask === 3) {
                            $valid = false;
                        }
                        for ($g = 0; $g < 2; $g++) {
                            if (($mask & (1 << $g)) !== 0) {
                                if (($edges & (1 << ($i * 2 + $g))) === 0) {
                                    $valid = false;
                                }
                                $amounts[$g] += $weights[$i];
                            }
                        }
                    }
                    if ($valid && $amounts[0] >= 3000 && $amounts[1] >= 4000) {
                        $expected = true;
                    }
                }
            }
        }
        $result = $this->solve($groups, $courses, $reuse);
        self::assertSame($expected ? Outcome::Feasible : Outcome::Infeasible, $result->outcome);
        if ($result->outcome === Outcome::Feasible) {
            $totals = [self::id(10) => 0, self::id(11) => 0];
            $used = [];
            foreach ($result->placements as $placement) {
                $key =
                    $reuse === Reuse::OneGroup
                        ? $placement["course_id"]
                        : $placement["course_id"] . $placement["group_id"];
                self::assertArrayNotHasKey($key, $used);
                $used[$key] = true;
                $totals[$placement["group_id"]] += $placement["credits_milli"];
            }
            self::assertGreaterThanOrEqual(3000, $totals[self::id(10)]);
            self::assertGreaterThanOrEqual(4000, $totals[self::id(11)]);
        }
    }

    public function testRepeatEligibleAwardsCanSupplyCreditsWithoutBecomingTwoCourseIdentities(): void
    {
        $choice = $this->choice(10, 1, 6000);
        $choice["work_ids"][] = self::id(1002);
        $course = $this->course(1, [$choice]);
        $result = $this->solve([$this->group(10, 6000, 1)], [$course]);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(1, $result->placements);
        self::assertCount(2, $result->placements[0]["work_ids"]);
        self::assertSame(Outcome::Infeasible, $this->solve([$this->group(10, 6000, 2)], [$course])->outcome);
    }

    public function testTheSameEvidenceCannotClaimDifferentAwardedCreditsForDifferentGroups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->problem(
            [$this->group(10), $this->group(11)],
            [$this->course(1, [$this->choice(10, 1, 3000), $this->choice(11, 1, 4000)])],
            Reuse::MultipleGroups,
        );
    }

    public function testInputCapsAndUnsupportedConstraintsAreRejectedInsteadOfIgnored(): void
    {
        $group = $this->group(10);
        $group["min_gpa_milli"] = 2000;
        try {
            $this->problem([$group], [], Reuse::OneGroup);
            self::fail("An unsupported GPA constraint was silently accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $courses = [];
        foreach (range(1, 201) as $id) {
            $courses[] = $this->course($id, []);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->problem([$this->group(10)], $courses, Reuse::OneGroup);
    }

    public function testAnInterruptedBranchCannotLeakItsPartialPlacements(): void
    {
        $courses = [
            $this->course(1, [$this->choice(10, 1)]),
            $this->course(2, [$this->choice(10, 2, work: 1002)]),
        ];
        $problem = $this->problem([$this->group(10, 6000, 2)], $courses, Reuse::OneGroup);
        $result = new CourseAllocationPlanner(10)->plan($problem);
        self::assertSame(Outcome::Undetermined, $result->outcome);
        self::assertSame([], $result->placements);
    }

    public function testSharingAcrossTwentyFiveGroupsDoesNotEnumerateEveryGroupSubset(): void
    {
        $rules = [];
        foreach (array_chunk(array_map(self::id(...), range(1, 200)), 50) as $i => $targets) {
            $rules[] = $this->rule("pool", $targets, id: 500 + $i);
        }
        $groups = [];
        foreach (range(1000, 1024) as $id) {
            $groups[] = $this->group($id, 600000, 200, $rules);
        }
        $courses = [];
        foreach (range(1, 200) as $id) {
            $choices = [];
            foreach (range(1000, 1024) as $group) {
                $choices[] = $this->choice($group, $id, 3000, 2000 + $id);
            }
            $courses[] = $this->course($id, $choices);
        }
        $result = $this->solve($groups, $courses, Reuse::MultipleGroups);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(5000, $result->placements);
        self::assertLessThanOrEqual(100000, $result->steps);
    }

    public function testMixedRequiredChooseWaiverAndAlternativeCasesAgreeWithLiteralEnumeration(): void
    {
        foreach (range(0, 31) as $seed) {
            foreach ([Reuse::OneGroup, Reuse::MultipleGroups] as $reuse) {
                $targets = [self::id(1), self::id(2), self::id(3)];
                $groups = [
                    $this->group(
                        10,
                        ($seed % 2) * 3000,
                        1,
                        [
                            $this->rule("all", [$targets[0]]),
                            $this->rule("choose", array_slice($targets, 1), 1, 101),
                        ],
                        $seed & 1 ? [$targets[0]] : [],
                    ),
                    $this->group(
                        11,
                        ($seed % 3) * 1000,
                        1,
                        [$this->rule("choose", $targets, ($seed % 2) + 1)],
                        $seed & 2 ? [$targets[1]] : [],
                    ),
                ];
                $courses = [];
                foreach (range(1, 3) as $id) {
                    $choices = [];
                    foreach ([10, 11] as $g) {
                        if ($seed & (1 << ($id + $g) % 5)) {
                            $choices[] = $this->choice($g, $id, ($id - 1) * 1000, 1000 + $id);
                        }
                        if ($seed & (1 << ($id + $g + 2) % 5)) {
                            $choices[] = $this->choice($g, ($id % 3) + 1, ($id - 1) * 1000, 1000 + $id);
                        }
                    }
                    $courses[] = $this->course($id, $choices);
                }
                $expected = $this->enumerate($groups, $courses, $reuse, 0, []);
                $result = $this->solve($groups, $courses, $reuse);
                self::assertSame(
                    $expected ? Outcome::Feasible : Outcome::Infeasible,
                    $result->outcome,
                    "Case " . $seed . ":" . $reuse->value,
                );
                if ($result->outcome === Outcome::Feasible) {
                    self::assertTrue($this->literalConstraints($groups, $result->placements));
                }
            }
        }
    }

    private function enumerate(array $groups, array $courses, Reuse $reuse, int $index, array $placed): bool
    {
        if ($index === count($courses)) {
            return $this->literalConstraints($groups, $placed);
        }
        $course = $courses[$index];
        $ways = [[]];
        if ($reuse === Reuse::OneGroup) {
            foreach ($course["choices"] as $choice) {
                $ways[] = [$choice];
            }
        } else {
            $choices = [];
            foreach ($course["choices"] as $choice) {
                $choices[$choice["group_id"]][] = $choice;
            }
            foreach ($choices as $groupChoices) {
                $next = $ways;
                foreach ($ways as $way) {
                    foreach ($groupChoices as $choice) {
                        $next[] = [...$way, $choice];
                    }
                }
                $ways = $next;
            }
        }
        foreach ($ways as $way) {
            $next = $placed;
            foreach ($way as $choice) {
                $next[] = ["course_id" => $course["course_id"]] + $choice;
            }
            if ($this->enumerate($groups, $courses, $reuse, $index + 1, $next)) {
                return true;
            }
        }
        return false;
    }

    private function literalConstraints(array $groups, array $placed): bool
    {
        $waived = array_merge(...array_column($groups, "waived_course_ids"));
        foreach ($groups as $group) {
            $targets = [];
            $actual = [];
            $credits = 0;
            foreach ($placed as $p) {
                if ($p["group_id"] !== $group["id"]) {
                    continue;
                }
                if (isset($targets[$p["target_course_id"]]) || isset($actual[$p["course_id"]])) {
                    return false;
                }
                $targets[$p["target_course_id"]] = true;
                $actual[$p["course_id"]] = true;
                $credits += $p["credits_milli"];
            }
            if ($credits < $group["min_credits_milli"] || count($actual) < $group["min_courses"]) {
                return false;
            }
            foreach ($group["rules"] as $rule) {
                if (
                    $rule["mode"] === "all" &&
                    array_diff($rule["course_ids"], array_keys($targets), $waived) !== []
                ) {
                    return false;
                }
                if (
                    $rule["mode"] === "choose" &&
                    count(array_intersect($rule["course_ids"], array_keys($targets))) <
                        $rule["minimum_courses"]
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    public function testARequiredCourseWaiverAppliesAcrossAllGroupsInOnlyThisProblem(): void
    {
        $groups = [
            $this->group(10, count: 0, rules: [$this->rule("all", [self::id(1)])], waived: [self::id(1)]),
            $this->group(11, count: 0, rules: [$this->rule("all", [self::id(1)])]),
        ];
        $waived = $this->solve($groups, []);
        self::assertSame(Outcome::Feasible, $waived->outcome);
        self::assertSame([], $waived->placements);
        $groups[0]["waived_course_ids"] = [];
        self::assertSame(Outcome::Infeasible, $this->solve($groups, [])->outcome);
    }

    public function testDuplicateEvidenceCannotCreateASecondCourseIdentity(): void
    {
        $courses = [$this->course(1, [$this->choice(10, 1)]), $this->course(2, [$this->choice(10, 2)])];
        $this->expectException(InvalidArgumentException::class);
        $this->problem([$this->group(10)], $courses, Reuse::OneGroup);
    }

    public function testUnknownTargetsAndDuplicateChoicesAreRejected(): void
    {
        foreach ([$this->choice(99, 1), $this->choice(10, 99)] as $choice) {
            try {
                $this->problem([$this->group(10)], [$this->course(1, [$choice])], Reuse::OneGroup);
                self::fail("Unknown target was accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->problem(
            [$this->group(10)],
            [$this->course(1, [$this->choice(10, 1), $this->choice(10, 1)])],
            Reuse::OneGroup,
        );
    }
}
