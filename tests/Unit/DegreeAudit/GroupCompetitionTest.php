<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class GroupCompetitionTest extends TestCase
{
    private static function id(int $id): string
    {
        return str_pad((string) $id, 26, "0", STR_PAD_LEFT);
    }
    private function rule(int $id, string $mode, array $targets, int $count = 0): array
    {
        return [
            "id" => self::id($id),
            "mode" => $mode,
            "minimum_courses" => $mode === "all" ? count($targets) : $count,
            "course_ids" => array_map(self::id(...), $targets),
        ];
    }
    private function group(int $id, int $credits = 3000, int $courses = 0, array $rules = []): array
    {
        return [
            "id" => self::id($id),
            "min_credits_milli" => $credits,
            "min_courses" => $courses,
            "rules" => $rules ?: [$this->rule($id + 100, "pool", [1, 2, 3, 4, 5, 6])],
        ];
    }
    private function course(int $id, array $groups, int $credits = 3000, ?int $target = null): array
    {
        return [
            "course_id" => self::id($id),
            "choices" => array_map(
                fn($g) => [
                    "group_id" => self::id($g),
                    "target_course_id" => self::id($target ?? $id),
                    "credits_milli" => $credits,
                    "work_ids" => [self::id(1000 + $id)],
                ],
                $groups,
            ),
        ];
    }
    private function problem(
        array $groups,
        array $courses,
        array $waived = [],
        CourseGroupReuse $reuse = CourseGroupReuse::OneGroup,
    ): AllocationProblem {
        return new AllocationProblem($groups, $courses, $reuse, array_map(self::id(...), $waived));
    }
    public function testSharedCreditShortageIsExplainedEvenWhenEachGroupHasEnoughEligibleWorkAlone(): void
    {
        $p = $this->problem(
            [$this->group(10, 6000), $this->group(20, 6000)],
            [$this->course(1, [10, 20]), $this->course(2, [10, 20])],
        );
        $analyzer = new AllocationGapAnalyzer();
        foreach ($analyzer->analyze($p) as $group) {
            self::assertFalse($group["has_shortfall"]);
        }
        $result = $analyzer->competition($p);
        self::assertFalse($result["more_conflicts"]);
        self::assertSame(
            [
                [
                    "group_ids" => [self::id(10), self::id(20)],
                    "required_credits_milli" => 12000,
                    "credit_upper_bound_milli" => 6000,
                    "credit_shortfall_milli" => 6000,
                    "required_courses" => 2,
                    "course_upper_bound" => 2,
                    "course_shortfall" => 0,
                ],
            ],
            $result["conflicts"],
        );
        self::assertSame(AllocationOutcome::Infeasible, new CourseAllocationPlanner()->plan($p)->outcome);
    }
    public function testCourseCountShortageIncludesZeroCreditCompletionsWithoutInventingCredits(): void
    {
        $p = $this->problem(
            [$this->group(10, 0, 2), $this->group(20, 0, 2)],
            [$this->course(1, [10, 20], 0), $this->course(2, [10, 20], 0), $this->course(3, [10, 20], 0)],
        );
        $c = new AllocationGapAnalyzer()->competition($p)["conflicts"][0];
        self::assertSame(4, $c["required_courses"]);
        self::assertSame(3, $c["course_upper_bound"]);
        self::assertSame(1, $c["course_shortfall"]);
        self::assertSame(0, $c["credit_shortfall_milli"]);
    }
    public function testWholeConnectedComponentCanProveAConflictNotPresentInAnyPair(): void
    {
        $p = $this->problem(
            [$this->group(10), $this->group(20), $this->group(30)],
            [$this->course(1, [10, 20, 30]), $this->course(2, [10, 20, 30])],
        );
        $r = new AllocationGapAnalyzer()->competition($p);
        self::assertCount(1, $r["conflicts"]);
        self::assertSame([self::id(10), self::id(20), self::id(30)], $r["conflicts"][0]["group_ids"]);
        self::assertSame(3000, $r["conflicts"][0]["credit_shortfall_milli"]);
        self::assertSame(1, $r["conflicts"][0]["course_shortfall"]);
    }
    public function testOverlappingRulesAreNotAddedAndWaivedAllRequirementsDoNotCreateCourseDemand(): void
    {
        $groups = [
            $this->group(
                10,
                0,
                rules: [$this->rule(110, "all", [1, 2]), $this->rule(111, "choose", [1, 2], 2)],
            ),
            $this->group(20, 0, rules: [$this->rule(120, "all", [1, 2])]),
        ];
        $courses = [
            $this->course(1, [10, 20], 0),
            $this->course(2, [10, 20], 0),
            $this->course(3, [10, 20], 0, 1),
            $this->course(4, [10, 20], 0, 2),
        ];
        self::assertSame(
            [],
            new AllocationGapAnalyzer()->competition($this->problem($groups, $courses))["conflicts"],
        );
        $p = $this->problem(
            [
                $this->group(10, 0, rules: [$this->rule(110, "all", [1, 2])]),
                $this->group(20, 0, rules: [$this->rule(120, "all", [1, 2])]),
            ],
            [$this->course(1, [10, 20], 0)],
            [2],
        );
        $c = new AllocationGapAnalyzer()->competition($p)["conflicts"][0];
        self::assertSame(2, $c["required_courses"]);
        self::assertSame(1, $c["course_shortfall"]);
    }
    public function testDifferentEligibleAmountsUseAnOptimisticMaximumRatherThanFalseScarcity(): void
    {
        $course = $this->course(1, [10, 20], 3000);
        $course["choices"][1]["credits_milli"] = 6000;
        $course["choices"][1]["work_ids"] = [self::id(2001)];
        $p = $this->problem([$this->group(10), $this->group(20)], [$course, $this->course(2, [10, 20], 0)]);
        self::assertSame([], new AllocationGapAnalyzer()->competition($p)["conflicts"]);
    }
    public function testSharingAndIndividualShortagesDoNotProduceCrossGroupClaims(): void
    {
        $groups = [$this->group(10), $this->group(20)];
        $courses = [$this->course(1, [10, 20])];
        self::assertSame(
            [],
            new AllocationGapAnalyzer()->competition(
                $this->problem($groups, $courses, reuse: CourseGroupReuse::MultipleGroups),
            )["conflicts"],
        );
        self::assertSame(
            [],
            new AllocationGapAnalyzer()->competition(
                $this->problem([$this->group(10, 6000), $this->group(20)], $courses),
            )["conflicts"],
        );
        self::assertSame(
            [],
            new AllocationGapAnalyzer()->competition(
                $this->problem($groups, [$this->course(1, [10]), $this->course(2, [20])]),
            )["conflicts"],
        );
    }
    public function testOutputIsBoundedStableAndAvoidsRedundantLargerSets(): void
    {
        $groups = array_map(fn($i) => $this->group(10 + $i), range(0, 24));
        $courses = [$this->course(1, range(10, 34))];
        $a = new AllocationGapAnalyzer();
        $r = $a->competition($this->problem($groups, $courses));
        self::assertCount(10, $r["conflicts"]);
        self::assertTrue($r["more_conflicts"]);
        self::assertSame($r, $a->competition($this->problem(array_reverse($groups), $courses)));
        foreach ($r["conflicts"] as $c) {
            self::assertCount(2, $c["group_ids"]);
        }
    }
    public function testLargeEligibleAmountsAreCappedWithoutOverflowOrFalseShortage(): void
    {
        $groups = array_map(fn($i) => $this->group(10 + $i, 9999999), range(0, 24));
        $targets = range(1, 6);
        $courses = array_map(
            fn($i) => $this->course($i, range(10, 34), 2147483646, $targets[($i - 1) % 6]),
            range(1, 200),
        );
        self::assertSame(
            [],
            new AllocationGapAnalyzer()->competition($this->problem($groups, $courses))["conflicts"],
        );
    }
    public function testEveryReportedShortageIsInfeasibleAcrossSmallDeterministicSearchCases(): void
    {
        $random = new Randomizer(new Mt19937(4871));
        $witnesses = $feasible = 0;
        for ($i = 0; $i < 200; $i++) {
            $groups = [];
            foreach ([10, 20, 30] as $id) {
                $mode = $random->getInt(0, 2);
                $rules =
                $mode === 0
                ? []
                : [
                            $this->rule(
                                100 + $id,
                                $mode === 1 ? "choose" : "all",
                                [1, 2],
                                $mode === 1 ? $random->getInt(1, 2) : 0,
                            ),
                        ];
                $groups[] = $this->group($id, 1500 * $random->getInt(0, 3), $random->getInt(0, 2), $rules);
            }
            $courses = [];
            foreach (range(1, 5) as $c) {
                $eligible = array_values(array_filter([10, 20, 30], fn() => $random->getInt(0, 1) === 1));
                $courses[] = $this->course($c, $eligible, 1500 * $random->getInt(0, 3), ($c % 2) + 1);
            }
            $p = $this->problem($groups, $courses, $random->getInt(0, 1) ? [2] : []);
            $r = new AllocationGapAnalyzer()->competition($p);
            $actual = new CourseAllocationPlanner()->plan($p)->outcome;
            if ($r["conflicts"] !== []) {
                $witnesses++;
                self::assertSame(AllocationOutcome::Infeasible, $actual);
            }
            if ($actual === AllocationOutcome::Feasible) {
                $feasible++;
                self::assertSame([], $r["conflicts"]);
            }
        }
        self::assertGreaterThan(0, $witnesses);
        self::assertGreaterThan(0, $feasible);
    }
}
