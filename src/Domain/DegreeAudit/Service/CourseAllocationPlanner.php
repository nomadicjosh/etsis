<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationResult;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use InvalidArgumentException;

/** Exact bounded structural placement, after complete-input, grading and repeat-policy evaluation. */
final readonly class CourseAllocationPlanner
{
    public function __construct(private int $maximumSteps = 100000)
    {
        if ($maximumSteps < 1 || $maximumSteps > 1000000) {
            throw new InvalidArgumentException("Use an allocation work limit between 1 and 1,000,000 steps.");
        }
    }

    public function plan(AllocationProblem $problem): AllocationResult
    {
        $problems = [$problem];
        if ($problem->sharing !== null) {
            $problems = [];
            foreach ($problem->sharing->partitions() as $groups) {
                $selected = array_fill_keys($groups, true);
                $courses = [];
                foreach ($problem->courses as $course) {
                    $choices = array_values(array_filter(
                        $course['choices'],
                        static fn(array $c): bool => isset($selected[$c['group_id']]),
                    ));
                    if ($choices !== []) {
                        $courses[] = ['course_id' => $course['course_id'], 'choices' => $choices];
                    }
                }
                $problems[] = new AllocationProblem(
                    array_values(array_filter(
                        $problem->groups,
                        static fn(array $g): bool => isset($selected[$g['id']]),
                    )),
                    $courses,
                    CourseGroupReuse::OneGroup,
                    $problem->waivedCourseIds,
                    $problem->sharing->subset($groups),
                );
            }
        } elseif ($problem->reuse === CourseGroupReuse::MultipleGroups) {
            // With sharing, groups are independent. Avoid enumerating subsets of every group per course.
            $problems = [];
            foreach ($problem->groups as $group) {
                $courses = [];
                foreach ($problem->courses as $course) {
                    $choices = array_values(
                        array_filter(
                            $course["choices"],
                            static fn(array $c): bool => $c["group_id"] === $group["id"],
                        ),
                    );
                    if ($choices !== []) {
                        $courses[] = ["course_id" => $course["course_id"], "choices" => $choices];
                    }
                }
                $problems[] = new AllocationProblem(
                    [$group],
                    $courses,
                    CourseGroupReuse::OneGroup,
                    $problem->waivedCourseIds,
                );
            }
        }
        $steps = 0;
        $placements = [];
        foreach ($problems as $part) {
            $search = new AllocationSearch($part, $this->maximumSteps - $steps);
            try {
                $found = $search->run();
            } catch (AllocationBudgetExhaustedException) {
                return new AllocationResult(AllocationOutcome::Undetermined, [], $steps + $search->steps());
            }
            $steps += $search->steps();
            if ($found === null) {
                return new AllocationResult(AllocationOutcome::Infeasible, [], $steps);
            }
            array_push($placements, ...$found);
        }
        usort(
            $placements,
            static fn(array $a, array $b): int => strcmp($a["group_id"], $b["group_id"]) ?:
            strcmp($a["target_course_id"], $b["target_course_id"]),
        );
        return new AllocationResult(AllocationOutcome::Feasible, $placements, $steps);
    }
}
