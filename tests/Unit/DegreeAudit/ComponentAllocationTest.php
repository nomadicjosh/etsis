<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\Catalog\ValueObject\ComponentCourseSharing as Sharing;
use Domain\DegreeAudit\Service\AllocationCoverageReview;
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
            if ($expected) {
                self::assertCount(3, new AllocationCoverageReview()->review($p, $result));
            }
        }
    }
}
