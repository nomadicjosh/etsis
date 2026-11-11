<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use PHPUnit\Framework\TestCase;

final class AllocationGapAnalyzerTest extends TestCase
{
    private static function id(int $id): string
    {
        return str_pad((string) $id, 26, "0", STR_PAD_LEFT);
    }

    private function group(int $id = 10, int $credits = 6000, int $courses = 0, array $rules = []): array
    {
        return [
            "id" => self::id($id),
            "min_credits_milli" => $credits,
            "min_courses" => $courses,
            "rules" => $rules ?: [$this->rule("pool", [1, 2, 3])],
        ];
    }

    private function rule(string $mode, array $targets, int $count = 0, int $id = 100): array
    {
        return [
            "id" => self::id($id),
            "mode" => $mode,
            "minimum_courses" => $mode === "all" ? count($targets) : $count,
            "course_ids" => array_map(self::id(...), $targets),
        ];
    }

    private function resource(int $id, array $choices): array
    {
        return [
            "course_id" => self::id($id),
            "choices" => array_map(
                fn($c) => [
                    "group_id" => self::id($c[0]),
                    "target_course_id" => self::id($c[1]),
                    "credits_milli" => $c[2],
                    "work_ids" => [self::id(1000 + $id)],
                ],
                $choices,
            ),
        ];
    }

    private function analyze(array $groups, array $courses, array $waived = []): array
    {
        return new AllocationGapAnalyzer()->analyze(
            new AllocationProblem(
                $groups,
                $courses,
                CourseGroupReuse::OneGroup,
                array_map(self::id(...), $waived),
            ),
        );
    }

    public function testFractionalCreditAndDistinctCourseShortagesAreNecessaryBoundsNotAwards(): void
    {
        $g = $this->analyze(
            [$this->group(credits: 6000, courses: 3)],
            [$this->resource(1, [[10, 1, 1500]]), $this->resource(2, [[10, 2, 2250]])],
        )[self::id(10)];
        self::assertTrue($g["has_shortfall"]);
        self::assertSame(3750, $g["credit_upper_bound_milli"]);
        self::assertSame(2250, $g["credit_shortfall_milli"]);
        self::assertSame(2, $g["course_upper_bound"]);
        self::assertSame(1, $g["course_shortfall"]);
        self::assertArrayNotHasKey("placements", $g);
        self::assertArrayNotHasKey("work_ids", $g);
    }

    public function testOriginalAndAlternativeCannotInflateOneTargetOrOneActualCourse(): void
    {
        foreach (
            [
                [$this->resource(1, [[10, 1, 3000]]), $this->resource(2, [[10, 1, 3000]])],
                [$this->resource(1, [[10, 1, 3000], [10, 2, 3000]])],
            ]
            as $resources
        ) {
            $g = $this->analyze([$this->group(courses: 2)], $resources)[self::id(10)];
            self::assertTrue($g["has_shortfall"]);
            self::assertSame(3000, $g["credit_upper_bound_milli"]);
            self::assertSame(1, $g["course_upper_bound"]);
        }
    }

    public function testWaiversRemoveAllObligationsButDoNotReduceChooseOrQuantityRequirements(): void
    {
        $rules = [$this->rule("all", [1, 2]), $this->rule("choose", [1, 2], 2, 101)];
        $g = $this->analyze([$this->group(rules: $rules)], [], [1])[self::id(10)];
        self::assertSame(6000, $g["credit_shortfall_milli"]);
        self::assertSame(1, $g["rules"][0]["required_courses"]);
        self::assertSame(1, $g["rules"][0]["shortfall_courses"]);
        self::assertSame(2, $g["rules"][1]["required_courses"]);
        self::assertSame(2, $g["rules"][1]["shortfall_courses"]);
        $actual = $this->analyze(
            [$this->group(credits: 3000, rules: [$this->rule("all", [1])])],
            [$this->resource(1, [[10, 1, 3000]])],
            [1],
        )[self::id(10)];
        self::assertFalse($actual["has_shortfall"]);
        self::assertSame(3000, $actual["credit_upper_bound_milli"]);
        self::assertSame(0, $actual["rules"][0]["required_courses"]);
    }

    public function testNamedShortageRemainsVisibleWhenCreditMinimumHasEnoughEvidence(): void
    {
        $g = $this->analyze(
            [$this->group(credits: 3000, rules: [$this->rule("all", [1, 2])])],
            [$this->resource(1, [[10, 1, 3000]])],
        )[self::id(10)];
        self::assertTrue($g["has_shortfall"]);
        self::assertSame(0, $g["credit_shortfall_milli"]);
        self::assertSame(1, $g["rules"][0]["shortfall_courses"]);
        self::assertSame([self::id(1)], $g["eligible_target_ids"]);
    }

    public function testZeroCreditCompletionIsEvidenceForNamedAndCourseCountRequirements(): void
    {
        $g = $this->analyze(
            [$this->group(credits: 0, courses: 1, rules: [$this->rule("all", [1])])],
            [$this->resource(1, [[10, 1, 0]])],
        )[self::id(10)];
        self::assertFalse($g["has_shortfall"]);
        self::assertSame(1, $g["course_upper_bound"]);
        self::assertSame(1, $g["rules"][0]["eligible_target_count"]);
    }

    public function testNoLocalShortageDoesNotClaimACompletePlanAcrossCompetingGroups(): void
    {
        $g = $this->analyze(
            [$this->group(10, 3000), $this->group(11, 3000)],
            [$this->resource(1, [[10, 1, 3000], [11, 1, 3000]])],
        );
        foreach ($g as $report) {
            self::assertFalse($report["has_shortfall"]);
            self::assertArrayNotHasKey("satisfied", $report);
        }
    }

    public function testBoundsMayOverestimateConflictingChoicesButNeverProveAFalseShortage(): void
    {
        // Two actual courses can only fill target 1; a third can fill targets 2 or 3.
        $g = $this->analyze(
            [$this->group(courses: 3, rules: [$this->rule("all", [1, 2, 3])])],
            [
                $this->resource(1, [[10, 1, 3000]]),
                $this->resource(2, [[10, 1, 3000]]),
                $this->resource(3, [[10, 2, 3000], [10, 3, 3000]]),
            ],
        )[self::id(10)];
        self::assertFalse($g["has_shortfall"]); // Search, not these bounds, must resolve this collision.
        self::assertSame(3, $g["course_upper_bound"]);
        self::assertSame(0, $g["rules"][0]["shortfall_courses"]);
    }

    public function testEveryProvedShortageIsInfeasibleByIndependentSmallGraphEnumeration(): void
    {
        // All 512 bipartite graphs on 3 resources and 3 targets, with uneven awards.
        for ($mask = 0; $mask < 512; $mask++) {
            $courses = [];
            $edges = [];
            for ($c = 1; $c <= 3; $c++) {
                $choices = [];
                for ($t = 1; $t <= 3; $t++) {
                    if ($mask & (1 << (($c - 1) * 3 + $t - 1))) {
                        $choices[] = [10, $t, $c * 1000];
                        $edges[$c][] = $t;
                    }
                }
                $courses[] = $this->resource($c, $choices);
            }
            $g = $this->analyze([$this->group(courses: 3)], $courses)[self::id(10)];
            $feasible = false;
            foreach ($edges[1] ?? [] as $a) {
                foreach ($edges[2] ?? [] as $b) {
                    foreach ($edges[3] ?? [] as $c) {
                        $feasible = $feasible || count(array_unique([$a, $b, $c])) === 3;
                    }
                }
            }
            if ($feasible) {
                self::assertFalse($g["has_shortfall"], "False shortage for graph " . $mask);
            }
            if ($g["has_shortfall"]) {
                self::assertFalse($feasible, "Proved shortage has a valid plan for graph " . $mask);
            }
        }
    }

    public function testMaximumSizedDistinctEvidenceSaturatesAtTheMinimumAndKeepsCanonicalReports(): void
    {
        $rules = [];
        foreach (array_chunk(range(1, 200), 50) as $i => $targets) {
            $rules[] = $this->rule("pool", $targets, id: 100 + $i);
        }
        $courses = array_map(fn($c) => $this->resource($c, [[10, $c, 2147483646]]), range(1, 200));
        $group = $this->group(credits: 9999999, courses: 200, rules: $rules);
        $reports = $this->analyze([$group], $courses);
        $g = $reports[self::id(10)];
        self::assertFalse($g["has_shortfall"]);
        self::assertSame(9999999, $g["credit_upper_bound_milli"]);
        self::assertSame(200, $g["course_upper_bound"]);
        self::assertCount(200, $g["eligible_target_ids"]);
        $group["rules"] = array_reverse($rules);
        self::assertSame($reports, $this->analyze([$group], array_reverse($courses)));
    }
}
