<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreditListRequirementsTest extends TestCase
{
    private static function id(int $id): string
    {
        return str_pad((string) $id, 26, "0", STR_PAD_LEFT);
    }
    private function rule(int $id, array $courses, int $credits): array
    {
        return [
            "id" => self::id($id),
            "mode" => "credits",
            "minimum_courses" => 0,
            "minimum_credits_milli" => $credits,
            "course_ids" => array_map(self::id(...), $courses),
        ];
    }
    private function group(int $id, array $rules, int $credits = 0): array
    {
        return [
            "id" => self::id($id),
            "min_credits_milli" => $credits,
            "min_courses" => 0,
            "rules" => $rules,
        ];
    }
    private function course(int $id, int $credits, array $groups = [10], ?int $target = null): array
    {
        return [
            "course_id" => self::id($id),
            "choices" => array_map(
                static fn(int $g): array => [
                    "group_id" => self::id($g),
                    "target_course_id" => self::id($target ?? $id),
                    "credits_milli" => $credits,
                    "work_ids" => [self::id(1000 + $id)],
                ],
                $groups,
            ),
        ];
    }
    public function testGroupTotalCannotReplaceItsSpecificListsCreditMinimum(): void
    {
        $group = $this->group(
            10,
            [
                $this->rule(100, [1, 2], 6000),
                [
                    "id" => self::id(101),
                    "mode" => "pool",
                    "minimum_courses" => 0,
                    "course_ids" => [self::id(3)],
                ],
            ],
            6000,
        );
        $problem = new AllocationProblem(
            [$group],
            [$this->course(1, 2000), $this->course(2, 2000), $this->course(3, 6000)],
            CourseGroupReuse::OneGroup,
        );
        self::assertSame(
            AllocationOutcome::Infeasible,
            new CourseAllocationPlanner()->plan($problem)->outcome,
        );
        $gap = new AllocationGapAnalyzer()->analyze($problem)[self::id(10)];
        self::assertSame(0, $gap["credit_shortfall_milli"]);
        self::assertTrue($gap["has_shortfall"]);
        self::assertSame(2000, $gap["rules"][0]["credit_shortfall_milli"]);
        self::assertSame(4000, $gap["rules"][0]["credit_upper_bound_milli"]);
    }
    public function testFractionalEarnedCreditsAndOverlappingCardsDoNotDuplicateTheGroupTotal(): void
    {
        $group = $this->group(10, [$this->rule(100, [1, 2], 3125), $this->rule(101, [1, 2], 3000)], 3125);
        $courses = [$this->course(1, 2125), $this->course(2, 1000)];
        $problem = new AllocationProblem([$group], $courses, CourseGroupReuse::OneGroup);
        $result = new CourseAllocationPlanner()->plan($problem);
        self::assertSame(AllocationOutcome::Feasible, $result->outcome);
        self::assertSame(3125, array_sum(array_column($result->placements, "credits_milli")));
        $group["min_credits_milli"] = 6250;
        self::assertSame(
            AllocationOutcome::Infeasible,
            new CourseAllocationPlanner()->plan(
                new AllocationProblem([$group], $courses, CourseGroupReuse::OneGroup),
            )->outcome,
        );
    }
    public function testWaiversAndAlternativeTargetsCannotManufactureCredit(): void
    {
        $group = $this->group(10, [$this->rule(100, [1, 2], 6000)]);
        $actual = $this->course(3, 3000, target: 1);
        $actual["choices"][] = array_replace($actual["choices"][0], ["target_course_id" => self::id(2)]);
        $problem = new AllocationProblem([$group], [$actual], CourseGroupReuse::OneGroup, [
            self::id(1),
            self::id(2),
        ]);
        self::assertSame(
            AllocationOutcome::Infeasible,
            new CourseAllocationPlanner()->plan($problem)->outcome,
        );
        $gap = new AllocationGapAnalyzer()->analyze($problem)[self::id(10)]["rules"][0];
        self::assertSame(3000, $gap["credit_upper_bound_milli"]);
        self::assertSame(3000, $gap["credit_shortfall_milli"]);
    }
    public function testDifferentAwardsForTheSameTargetRequireCreditSensitiveSearchState(): void
    {
        $group = $this->group(10, [$this->rule(100, [1], 4000)]);
        $problem = new AllocationProblem(
            [$group],
            [$this->course(2, 2000, target: 1), $this->course(3, 4000, target: 1)],
            CourseGroupReuse::OneGroup,
        );
        $result = new CourseAllocationPlanner()->plan($problem);
        self::assertSame(AllocationOutcome::Feasible, $result->outcome);
        self::assertSame(self::id(3), $result->placements[0]["course_id"]);
        self::assertSame(
            AllocationOutcome::Undetermined,
            new CourseAllocationPlanner(1)->plan($problem)->outcome,
        );
        self::assertSame([], new CourseAllocationPlanner(1)->plan($problem)->placements);
    }
    public function testCreditCardsRespectSharingAndExplainCrossGroupShortages(): void
    {
        $groups = [
            $this->group(10, [$this->rule(100, [1], 3000)]),
            $this->group(11, [$this->rule(101, [1], 3000)]),
        ];
        $courses = [$this->course(1, 3000, [10, 11])];
        $problem = new AllocationProblem($groups, $courses, CourseGroupReuse::OneGroup);
        self::assertSame(
            AllocationOutcome::Infeasible,
            new CourseAllocationPlanner()->plan($problem)->outcome,
        );
        $conflicts = new AllocationGapAnalyzer()->competition($problem)["conflicts"];
        self::assertCount(1, $conflicts);
        self::assertSame(3000, $conflicts[0]["credit_shortfall_milli"]);
        $shared = new CourseAllocationPlanner()->plan(
            new AllocationProblem($groups, $courses, CourseGroupReuse::MultipleGroups),
        );
        self::assertSame(AllocationOutcome::Feasible, $shared->outcome);
        self::assertCount(2, $shared->placements);
    }
    public function testValidationRejectsMissingZeroOrUnexpectedCreditThresholds(): void
    {
        $d = CourseGroupDefinitionV2::blank("Credit list")->definition;
        $d["min_credits_milli"] = 6000;
        $d["courses"] = [["course_id" => self::id(1), "version_id" => self::id(201)]];
        $d["rules"] = [$this->rule(100, [1], 6000)];
        new CourseGroupDefinitionV2($d)->assertPublishable();
        foreach (["missing", "zero", "negative", "string", "pool", "count"] as $kind) {
            $bad = $d;
            switch ($kind) {
                case "missing":
                    unset($bad["rules"][0]["minimum_credits_milli"]);
                    break;
                case "zero":
                    $bad["rules"][0]["minimum_credits_milli"] = 0;
                    break;
                case "negative":
                    $bad["rules"][0]["minimum_credits_milli"] = -1;
                    break;
                case "string":
                    $bad["rules"][0]["minimum_credits_milli"] = "6000";
                    break;
                case "pool":
                    $bad["rules"][0]["mode"] = "pool";
                    break;
                case "count":
                    $bad["rules"][0]["minimum_courses"] = 1;
                    break;
            }
            try {
                new CourseGroupDefinitionV2($bad);
                self::fail("Invalid credit rule accepted: " . $kind);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testWeightedOverlappingListsAgreeWithIndependentExhaustiveAssignments(): void
    {
        for ($case = 0; $case < 64; $case++) {
            $minA = 1500 + ($case % 3) * 1250;
            $minB = 2000 + ($case % 4) * 1000;
            $groups = [
                $this->group(10, [$this->rule(100, [1, 2, 3], $minA)]),
                $this->group(11, [$this->rule(101, [2, 3, 4], $minB)]),
            ];
            $courses = $amounts = [];
            for ($course = 1; $course <= 4; $course++) {
                $amounts[$course] = [0, 1250, 3000][intdiv($case * 7 + 3, 3 ** ($course - 1)) % 3];
                $courses[] = $this->course(
                    $course,
                    $amounts[$course],
                    $course === 1 ? [10] : ($course === 4 ? [11] : [10, 11]),
                );
            }
            $feasible = false;
            for ($assignment = 0; $assignment < 81; $assignment++) {
                $a = $b = 0;
                for ($course = 1; $course <= 4; $course++) {
                    $choice = intdiv($assignment, 3 ** ($course - 1)) % 3;
                    if ($choice === 1 && $course !== 4) {
                        $a += $amounts[$course];
                    }
                    if ($choice === 2 && $course !== 1) {
                        $b += $amounts[$course];
                    }
                }
                $feasible = $feasible || ($a >= $minA && $b >= $minB);
            }
            $problem = new AllocationProblem($groups, $courses, CourseGroupReuse::OneGroup);
            $result = new CourseAllocationPlanner()->plan($problem);
            self::assertSame(
                $feasible ? AllocationOutcome::Feasible : AllocationOutcome::Infeasible,
                $result->outcome,
                "Case " . $case,
            );
            $reversed = new AllocationProblem(
                array_reverse($groups),
                array_reverse($courses),
                CourseGroupReuse::OneGroup,
            );
            self::assertSame($result->placements, new CourseAllocationPlanner()->plan($reversed)->placements);
        }
    }
}
