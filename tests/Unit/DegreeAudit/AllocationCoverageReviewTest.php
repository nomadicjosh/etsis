<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\AllocationCoverageReview;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationResult;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AllocationCoverageReviewTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, "0", STR_PAD_LEFT);
    }

    private function group(int $id, int $credits, int $courses, array $rules): array
    {
        return [
            "id" => self::id($id),
            "min_credits_milli" => $credits,
            "min_courses" => $courses,
            "rules" => $rules,
        ];
    }

    private function rule(int $id, string $mode, array $courses, int $minimum = 0): array
    {
        return [
            "id" => self::id($id),
            "mode" => $mode,
            "course_ids" => array_map(self::id(...), $courses),
            "minimum_courses" => $mode === "all" ? count($courses) : ($mode === "choose" ? $minimum : 0),
        ] + ($mode === "credits" ? ["minimum_credits_milli" => $minimum] : []);
    }

    private function course(int $id, int $group, int $target, int $credits, ?array $amounts = null): array
    {
        return [
            "course_id" => self::id($id),
            "choices" => [
                [
                    "group_id" => self::id($group),
                    "target_course_id" => self::id($target),
                    "credits_milli" => $credits,
                    "work_ids" => [self::id($id + 1000)],
                ] + ($amounts !== null ? ["rule_credits_milli" => $amounts] : []),
            ],
        ];
    }

    public function testWaiversZeroCreditCompletionsAndOverlappingCardsHaveSeparateCounts(): void
    {
        $problem = new AllocationProblem(
            [
                $this->group(1, 3000, 2, [
                    $this->rule(10, "all", [20, 21, 22]),
                    $this->rule(11, "choose", [20, 21, 22], 2),
                    $this->rule(12, "credits", [20, 21], 3000),
                    $this->rule(13, "pool", [20, 21]),
                ]),
            ],
            [$this->course(100, 1, 20, 3000), $this->course(101, 1, 21, 0)],
            CourseGroupReuse::OneGroup,
            [self::id(22)],
        );
        $result = new CourseAllocationPlanner()->plan($problem);
        $c = new AllocationCoverageReview()->review($problem, $result)[self::id(1)];
        self::assertSame(3000, $c["applied_credits_milli"]);
        self::assertSame(2, $c["applied_courses"]);
        self::assertSame(2, $c["rules"][self::id(10)]["required_courses"]);
        self::assertSame(2, $c["rules"][self::id(10)]["covered_courses"]);
        self::assertSame(1, $c["rules"][self::id(10)]["waived_courses"]);
        self::assertSame(2, $c["rules"][self::id(11)]["required_courses"]);
        self::assertSame(0, $c["rules"][self::id(11)]["waived_courses"]);
        self::assertSame(3000, $c["rules"][self::id(12)]["applied_credits_milli"]);
        self::assertNull($c["rules"][self::id(13)]["required_courses"]);
        self::assertNull($c["rules"][self::id(13)]["required_credits_milli"]);
        $waivedWork = new AllocationProblem(
            $problem->groups,
            $problem->courses,
            CourseGroupReuse::OneGroup,
            [self::id(21), self::id(22)],
        );
        $review = new AllocationCoverageReview()->review($waivedWork, new CourseAllocationPlanner()->plan($waivedWork));
        $c = $review[self::id(1)];
        self::assertSame(2, $c['applied_courses']);
        self::assertSame(3000, $c['applied_credits_milli']);
        self::assertSame(1, $c['rules'][self::id(10)]['covered_courses']);
        self::assertSame(1, $c['rules'][self::id(10)]['required_courses']);
        self::assertSame(2, $c['rules'][self::id(10)]['waived_courses']);
        self::assertSame(2, $c['rules'][self::id(11)]['required_courses']);
    }

    public function testEachCardReceivesOnlyItsOwnRetainedAwardContribution(): void
    {
        $rules = [
            $this->rule(10, "all", [20]),
            $this->rule(11, "credits", [20], 3000),
            $this->rule(12, "credits", [20], 4000),
        ];
        $course = $this->course(100, 1, 20, 7000, [
            self::id(10) => 0,
            self::id(11) => 3000,
            self::id(12) => 4000,
        ]);
        $course["choices"][0]["work_ids"][] = self::id(1101);
        $problem = new AllocationProblem(
            [$this->group(1, 7000, 1, $rules)],
            [$course],
            CourseGroupReuse::OneGroup,
        );
        $review = new AllocationCoverageReview()->review($problem, new CourseAllocationPlanner()->plan($problem));
        $c = $review[self::id(1)];
        self::assertSame(1, $c["applied_courses"]);
        self::assertSame(7000, $c["applied_credits_milli"]);
        self::assertSame([0, 3000, 4000], array_column($c["rules"], "applied_credits_milli"));
        self::assertSame([1, 1, 1], array_column($c["rules"], "covered_courses"));
    }

    public function testOneActualCourseCanSupportSeparateSharedGroupsWithoutCreatingAnOverallTotal(): void
    {
        $a = $this->group(1, 3000, 1, [$this->rule(10, "all", [20])]);
        $b = $this->group(2, 3000, 1, [$this->rule(11, "all", [21])]);
        $course = $this->course(100, 1, 20, 3000);
        $course["choices"][] = $this->course(100, 2, 21, 3000)["choices"][0];
        $problem = new AllocationProblem([$a, $b], [$course], CourseGroupReuse::MultipleGroups);
        $review = new AllocationCoverageReview()->review(
            $problem,
            new CourseAllocationPlanner()->plan($problem),
        );
        self::assertSame([self::id(1), self::id(2)], array_keys($review));
        foreach ($review as $c) {
            self::assertSame(3000, $c["applied_credits_milli"]);
            self::assertSame(1, $c["applied_courses"]);
            self::assertSame(1, array_values($c["rules"])[0]["covered_courses"]);
        }
        $one = new AllocationProblem([$a, $b], [$course], CourseGroupReuse::OneGroup);
        $shared = new CourseAllocationPlanner()->plan($problem);
        $this->expectException(InvalidArgumentException::class);
        new AllocationCoverageReview()->review($one, $shared);
    }

    public function testUnknownOrUnsuccessfulResultsNeverBecomeZeroAppliedTotalsOrPartialCoverage(): void
    {
        $problem = new AllocationProblem(
            [$this->group(1, 3000, 1, [$this->rule(10, "all", [20])])],
            [],
            CourseGroupReuse::OneGroup,
        );
        foreach ([AllocationOutcome::Infeasible, AllocationOutcome::Undetermined] as $outcome) {
            self::assertSame(
                [],
                new AllocationCoverageReview()->review($problem, new AllocationResult($outcome, [], 1)),
            );
        }
    }

    public function testMismatchedForgedOrIncompletePlansCannotClaimRequirementsWereCovered(): void
    {
        $course = $this->course(100, 1, 20, 3000);
        $problem = new AllocationProblem(
            [$this->group(1, 3000, 1, [$this->rule(10, "all", [20])])],
            [$course],
            CourseGroupReuse::OneGroup,
        );
        $p = ["course_id" => self::id(100)] + $course["choices"][0];
        $cases = [[], [$p, $p]];
        $changes = [
                ["credits_milli" => 4000],
                ["course_id" => self::id(101)],
                ["target_course_id" => self::id(21)],
                ["group_id" => self::id(2)],
                ["work_ids" => [self::id(1101)]],
                ["rule_credits_milli" => [self::id(10) => 4000]],
                ["unreviewed" => true],
        ];
        foreach ($changes as $change) {
            $cases[] = [array_replace($p, $change)];
        }
        foreach ($cases as $placements) {
            try {
                new AllocationCoverageReview()->review(
                    $problem,
                    new AllocationResult(AllocationOutcome::Feasible, $placements, 1),
                );
                self::fail("A mismatched proposed plan produced coverage.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMaximumSharedPlacementCountRetainsBoundedPerCardSummaries(): void
    {
        $groups = $courses = $placements = [];
        $targets = range(100, 299);
        foreach (range(1, 25) as $id) {
            $groups[] = $this->group($id, 600000, 200, [$this->rule(500, "all", $targets)]);
        }
        foreach ($targets as $target) {
            $course = ["course_id" => self::id($target), "choices" => []];
            foreach (range(1, 25) as $id) {
                $choice = $this->course($target, $id, $target, 3000)["choices"][0];
                $course["choices"][] = $choice;
                $placements[] = ["course_id" => self::id($target)] + $choice;
            }
            $courses[] = $course;
        }
        $problem = new AllocationProblem($groups, $courses, CourseGroupReuse::MultipleGroups);
        $coverage = new AllocationCoverageReview()->review(
            $problem,
            new AllocationResult(AllocationOutcome::Feasible, $placements, 1),
        );
        self::assertCount(25, $coverage);
        foreach ($coverage as $group) {
            self::assertSame(600000, $group["applied_credits_milli"]);
            self::assertSame(200, $group["applied_courses"]);
            self::assertSame(200, $group["rules"][self::id(500)]["covered_courses"]);
            self::assertArrayNotHasKey("work_ids", $group);
            self::assertArrayNotHasKey("targets", $group);
        }
    }

    public function testCoverageUsesCanonicalGroupsAndRulesWithoutChangingItsInputs(): void
    {
        $groups = [$this->group(1, 0, 0, [$this->rule(10, "all", [20])])];
        $problem = new AllocationProblem($groups, [], CourseGroupReuse::OneGroup, [self::id(20)]);
        $result = new CourseAllocationPlanner()->plan($problem);
        $before = serialize([$problem, $result]);
        $c = new AllocationCoverageReview()->review($problem, $result)[self::id(1)];
        self::assertSame($before, serialize([$problem, $result]));
        self::assertSame(0, $c["applied_courses"]);
        self::assertSame(0, $c["applied_credits_milli"]);
        self::assertSame(1, $c["rules"][self::id(10)]["waived_courses"]);
        self::assertSame(0, $c["rules"][self::id(10)]["required_courses"]);
    }
}
