<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\Catalog\ValueObject\ComponentCourseSharing as Sharing;
use Domain\DegreeAudit\Service\AllocationCoverageReview;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\ValueObject\AllocationOutcome as Outcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationSharing;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse as Reuse;
use PHPUnit\Framework\TestCase;

final class ComponentAllocationTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, '0', STR_PAD_LEFT);
    }
    private function problem(Sharing $sharing, Reuse $degree, Reuse $specialization, array $membership = [[0,1,2]], array $minimums = [1,1,1]): AllocationProblem
    {
        $groups = $courses = [];
        foreach ($minimums as $i => $minimum) {
            $groups[] = ['id' => self::id(10 + $i),'min_credits_milli' => 3000 * $minimum,'min_courses' => $minimum,
                'rules' => [['id' => self::id(20 + $i),'mode' => 'pool','minimum_courses' => 0,
                    'course_ids' => array_map(self::id(...), range(1, count($membership)))]]];
        }
        foreach ($membership as $i => $indices) {
            $choices = [];
            foreach ($indices as $g) {
                $choices[] = ['group_id' => self::id(10 + $g),'target_course_id' => self::id($i + 1),
                    'credits_milli' => 3000,'work_ids' => [self::id(100 + $i)]];
            }
            $courses[] = ['course_id' => self::id($i + 1),'choices' => $choices];
        }
        return new AllocationProblem($groups, $courses, Reuse::OneGroup, [], new AllocationSharing(
            [self::id(10) => self::id(50),self::id(11) => self::id(50),self::id(12) => self::id(51)],
            [self::id(50) => $degree,self::id(51) => $specialization],
            $sharing,
        ));
    }
    public function testComponentSharingAndGroupSharingRemainIndependent(): void
    {
        $planner = new CourseAllocationPlanner();
        $one = $this->problem(Sharing::OneComponent, Reuse::MultipleGroups, Reuse::OneGroup);
        self::assertSame(Outcome::Infeasible, $planner->plan($one)->outcome);
        $separate = $this->problem(Sharing::MultipleComponents, Reuse::OneGroup, Reuse::OneGroup);
        self::assertSame(Outcome::Infeasible, $planner->plan($separate)->outcome);
        $shared = $this->problem(Sharing::MultipleComponents, Reuse::MultipleGroups, Reuse::OneGroup);
        $result = $planner->plan($shared);
        self::assertSame(Outcome::Feasible, $result->outcome);
        self::assertCount(3, $result->placements);
        self::assertCount(3, new AllocationCoverageReview()->review($shared, $result));
        $this->expectException(\InvalidArgumentException::class);
        new AllocationCoverageReview()->review($one, $result);
    }
    public function testAResourceCanFillSeveralGroupsInOneComponentWithoutBeingUsedByAnother(): void
    {
        $p = $this->problem(Sharing::OneComponent, Reuse::MultipleGroups, Reuse::OneGroup, [[0,1,2],[0,1,2]]);
        $result = new CourseAllocationPlanner()->plan($p);
        self::assertSame(Outcome::Feasible, $result->outcome);
        $byCourse = [];
        foreach ($result->placements as $placement) {
            $byCourse[$placement['course_id']][] = $placement['group_id'];
        }
        self::assertCount(2, $byCourse);
        $counts = array_map(count(...), array_values($byCourse));
        sort($counts);
        self::assertSame([1,2], $counts);
        self::assertCount(3, new AllocationCoverageReview()->review($p, $result));
    }
    public function testAnExclusionAlreadyAppliedToEligibleChoicesCannotBeBypassed(): void
    {
        $p = $this->problem(Sharing::MultipleComponents, Reuse::MultipleGroups, Reuse::OneGroup, [[0,1],[2]]);
        $result = new CourseAllocationPlanner()->plan($p);
        self::assertSame(Outcome::Feasible, $result->outcome);
        foreach ($result->placements as $placement) {
            self::assertSame($placement['group_id'] === self::id(12) ? self::id(2) : self::id(1), $placement['course_id']);
        }
    }
    public function testPartialIndependentResultsAreDiscardedWhenTheSharedBudgetIsExhausted(): void
    {
        $p = $this->problem(Sharing::MultipleComponents, Reuse::MultipleGroups, Reuse::OneGroup);
        $result = new CourseAllocationPlanner(15)->plan($p);
        self::assertSame(Outcome::Undetermined, $result->outcome);
        self::assertSame([], $result->placements);
        self::assertSame([], new AllocationCoverageReview()->review($p, $result));
    }
    public function testMalformedOrIncompleteComponentMapsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AllocationSharing([self::id(10) => self::id(50)], [], Sharing::OneComponent);
    }
    public function testExactSearchAgreesWithExhaustiveSmallMixedPolicyProblems(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(481));
        foreach (range(1, 120) as $case) {
            $sharing = $case % 2 ? Sharing::OneComponent : Sharing::MultipleComponents;
            $degree = $case % 3 ? Reuse::OneGroup : Reuse::MultipleGroups;
            $membership = [];
            foreach (range(1, 3) as $i) {
                $membership[] = array_values(array_filter([0,1,2], fn() => $random->getInt(0, 1) === 1));
            }
            $minimums = [$random->getInt(1, 2),$random->getInt(1, 2),$random->getInt(1, 2)];
            $p = $this->problem($sharing, $degree, Reuse::OneGroup, $membership, $minimums);
            // Independent exhaustive oracle: choose every allowed subset of eligible groups per course.
            $solve = function (int $i, array $counts) use (&$solve, $membership, $minimums, $sharing, $degree): bool {
                if ($i === count($membership)) {
                    return $counts[0] >= $minimums[0] && $counts[1] >= $minimums[1] && $counts[2] >= $minimums[2];
                }
                $eligible = $membership[$i];
                for ($mask = 0; $mask < (1 << count($eligible)); $mask++) {
                    $selected = [];
                    foreach ($eligible as $bit => $g) {
                        if ($mask & (1 << $bit)) {
                            $selected[] = $g;
                        }
                    }
                    $a = count(array_intersect($selected, [0,1]));
                    $b = in_array(2, $selected, true);
                    if (($degree === Reuse::OneGroup && $a > 1) || ($sharing === Sharing::OneComponent && $a && $b)) {
                        continue;
                    }
                    $next = $counts;
                    foreach ($selected as $g) {
                        $next[$g]++;
                    }
                    if ($solve($i + 1, $next)) {
                        return true;
                    }
                }
                return false;
            };
            $expected = $solve(0, [0,0,0]);
            $result = new CourseAllocationPlanner()->plan($p);
            self::assertSame($expected ? Outcome::Feasible : Outcome::Infeasible, $result->outcome, 'Case ' . $case);
            $conflicts = new AllocationGapAnalyzer()->competition($p)['conflicts'];
            if ($expected) {
                self::assertSame([], $conflicts, 'False shortage for feasible case ' . $case);
                self::assertCount(3, new AllocationCoverageReview()->review($p, $result));
            }
            if ($conflicts !== []) {
                self::assertFalse($expected);
            }
        }
    }
    public function testOneCourseContributionBoundsAgreeWithExhaustiveAllowedSubsets(): void
    {
        foreach ([Sharing::OneComponent, Sharing::MultipleComponents] as $sharing) {
            foreach ([Reuse::OneGroup, Reuse::MultipleGroups] as $degree) {
                foreach ([Reuse::OneGroup, Reuse::MultipleGroups] as $specialization) {
                    $policy = new AllocationSharing(
                        [
                            self::id(10) => self::id(50),
                            self::id(11) => self::id(50),
                            self::id(12) => self::id(51),
                            self::id(13) => self::id(51),
                        ],
                        [self::id(50) => $degree, self::id(51) => $specialization],
                        $sharing,
                    );
                    for ($available = 0; $available < 16; $available++) {
                        $amounts = [];
                        foreach ([1500, 3000, 4500, 2000] as $i => $amount) {
                            if ($available & (1 << $i)) {
                                $amounts[self::id(10 + $i)] = $amount;
                            }
                        }
                        $expected = 0;
                        $ids = array_keys($amounts);
                        for ($mask = 0; $mask < 1 << count($ids); $mask++) {
                            $used = [];
                            $total = 0;
                            foreach ($ids as $i => $id) {
                                if (!($mask & (1 << $i))) {
                                    continue;
                                }
                                if (!$policy->permits($used, $id)) {
                                    continue 2;
                                }
                                $used[] = $id;
                                $total += $amounts[$id];
                            }
                            $expected = max($expected, $total);
                        }
                        foreach ([0, 1000, 5000, PHP_INT_MAX] as $ceiling) {
                            self::assertSame(
                                min($expected, $ceiling),
                                $policy->contributionUpperBound($amounts, $ceiling),
                            );
                        }
                    }
                }
            }
        }
    }
    public function testContributionBoundsSaturateWithoutOverflowAndRejectUnknownGroups(): void
    {
        $policy = $this->problem(Sharing::MultipleComponents, Reuse::MultipleGroups, Reuse::OneGroup)->sharing;
        self::assertNotNull($policy);
        self::assertSame(
            PHP_INT_MAX,
            $policy->contributionUpperBound(
                [self::id(10) => PHP_INT_MAX, self::id(11) => PHP_INT_MAX, self::id(12) => PHP_INT_MAX],
                PHP_INT_MAX,
            ),
        );
        foreach ([[self::id(99) => 1], [self::id(10) => -1]] as $invalid) {
            try {
                $policy->contributionUpperBound($invalid, 100);
                self::fail("Invalid contribution accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testMixedSharingShortagesAreBoundsForTheSameRetainedPlacementRules(): void
    {
        $gaps = new AllocationGapAnalyzer();
        foreach ([Sharing::OneComponent, Sharing::MultipleComponents] as $sharing) {
            foreach ([Reuse::OneGroup, Reuse::MultipleGroups] as $degree) {
                $p = $this->problem($sharing, $degree, Reuse::OneGroup);
                $conflicts = $gaps->competition($p)["conflicts"];
                if ($sharing === Sharing::MultipleComponents && $degree === Reuse::MultipleGroups) {
                    self::assertSame([], $conflicts);
                } else {
                    self::assertNotEmpty($conflicts);
                    self::assertSame(Outcome::Infeasible, new CourseAllocationPlanner()->plan($p)->outcome);
                }
            }
        }
    }
    public function testThreeComponentsCanHaveAProvenShortageEvenWhenEveryPairHasEnoughWork(): void
    {
        $base = $this->problem(Sharing::MultipleComponents, Reuse::MultipleGroups, Reuse::OneGroup, [
            [0, 1, 2],
            [0, 1, 2],
        ]);
        $sharing = new AllocationSharing(
            [self::id(10) => self::id(50), self::id(11) => self::id(51), self::id(12) => self::id(52)],
            [self::id(50) => Reuse::OneGroup, self::id(51) => Reuse::OneGroup, self::id(52) => Reuse::OneGroup],
            Sharing::OneComponent,
        );
        $p = new AllocationProblem($base->groups, $base->courses, Reuse::OneGroup, [], $sharing);
        $result = new AllocationGapAnalyzer()->competition($p);
        self::assertCount(1, $result["conflicts"]);
        $conflict = $result["conflicts"][0];
        self::assertCount(3, $conflict["group_ids"]);
        self::assertSame(9000, $conflict["required_credits_milli"]);
        self::assertSame(6000, $conflict["credit_upper_bound_milli"]);
        self::assertSame(3000, $conflict["credit_shortfall_milli"]);
        self::assertSame(1, $conflict["course_shortfall"]);
        self::assertSame(
            $result,
            new AllocationGapAnalyzer()->competition(
                new AllocationProblem(
                    array_reverse($base->groups),
                    array_reverse($base->courses),
                    Reuse::OneGroup,
                    [],
                    $sharing,
                ),
            ),
        );
    }

    public function testMixedSharingCompetitionIsCappedAndSkipsIndividuallyShortGroups(): void
    {
        $groups = $map = $reuse = $choices = [];
        for ($i = 0; $i < 6; $i++) {
            $group = self::id(10 + $i);
            $component = self::id(50 + $i);
            $groups[] = [
                "id" => $group,
                "min_credits_milli" => 3000,
                "min_courses" => 1,
                "rules" => [
                    [
                        "id" => self::id(20 + $i),
                        "mode" => "pool",
                        "minimum_courses" => 0,
                        "course_ids" => [self::id(1)],
                    ],
                ],
            ];
            $choices[] = [
                "group_id" => $group,
                "target_course_id" => self::id(1),
                "credits_milli" => 3000,
                "work_ids" => [self::id(100)],
            ];
            $map[$group] = $component;
            $reuse[$component] = Reuse::OneGroup;
        }
        $sharing = new AllocationSharing($map, $reuse, Sharing::OneComponent);
        $courses = [["course_id" => self::id(1), "choices" => $choices]];
        $analyzer = new AllocationGapAnalyzer();
        $result = $analyzer->competition(new AllocationProblem($groups, $courses, Reuse::OneGroup, [], $sharing));
        self::assertCount(10, $result["conflicts"]);
        self::assertTrue($result["more_conflicts"]);
        foreach ($result["conflicts"] as $conflict) {
            self::assertSame(1, $conflict["course_shortfall"]);
            self::assertSame(3000, $conflict["credit_shortfall_milli"]);
        }
        // Removing a group's eligible evidence makes it an individual shortage, not a competition explanation.
        array_pop($courses[0]["choices"]);
        $result = $analyzer->competition(new AllocationProblem($groups, $courses, Reuse::OneGroup, [], $sharing));
        foreach ($result["conflicts"] as $conflict) {
            self::assertNotContains(self::id(15), $conflict["group_ids"]);
        }
    }

}
