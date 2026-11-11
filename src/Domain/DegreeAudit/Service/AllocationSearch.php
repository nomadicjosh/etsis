<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException;
use Domain\DegreeAudit\ValueObject\AllocationProblem;

/**
 * @internal One bounded search invocation. No application state, I/O or shared cache.
 * @phpstan-import-type CoursePlacement from \Domain\DegreeAudit\ValueObject\AllocationResult
 */
final class AllocationSearch
{
    private int $steps = 0;
    /** @var array<string,true> */
    private array $failed = [];
    /** @var array<string,array<string,mixed>> */
    private array $groups = [];
    /** @var array<string,array<string,int>> */
    private array $last = [];
    /** @var array<int,array<string,int>> */
    private array $remainingCredits = [];
    /** @var array<int,array<string,int>> */
    private array $remainingCourses = [];

    public function __construct(
        private readonly AllocationProblem $problem,
        private readonly int $maximumSteps,
    ) {
    }

    /** @return list<CoursePlacement>|null */
    public function run(): ?array
    {
        $state = [];
        $waived = array_fill_keys($this->problem->waivedCourseIds, true);
        foreach ($this->problem->groups as $group) {
            $id = $group["id"];
            $this->groups[$id] = $group + ["waived" => $waived];
            $state[$id] = ["credits" => 0, "targets" => []];
        }
        $count = count($this->problem->courses);
        $credits = array_fill_keys(array_keys($this->groups), 0);
        $courses = $credits;
        $this->remainingCredits[$count] = $credits;
        $this->remainingCourses[$count] = $courses;
        for ($i = $count - 1; $i >= 0; $i--) {
            $byGroup = [];
            foreach ($this->problem->courses[$i]["choices"] as $choice) {
                $this->spend();
                $g = $choice["group_id"];
                $target = $choice["target_course_id"];
                $this->last[$g][$target] ??= $i;
                $byGroup[$g] = max($byGroup[$g] ?? 0, $choice["credits_milli"]);
            }
            foreach ($byGroup as $g => $maximum) {
                $required = $this->groups[$g]["min_credits_milli"];
                $credits[$g] = min($required, $credits[$g] + min($required, $maximum));
                $courses[$g]++;
            }
            $this->remainingCredits[$i] = $credits;
            $this->remainingCourses[$i] = $courses;
        }
        return $this->search(0, $state, []);
    }

    public function steps(): int
    {
        return $this->steps;
    }

    /** @param array<string,array{credits:int,targets:array<string,true>}> $state
     * @param list<CoursePlacement> $placements
     * @return list<CoursePlacement>|null
     */
    private function search(int $index, array $state, array $placements): ?array
    {
        $this->spend();
        $complete = true;
        foreach ($this->groups as $id => $group) {
            if (!$this->met($group, $state[$id])) {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            return $placements;
        }
        if ($index >= count($this->problem->courses) || !$this->possible($index, $state)) {
            return null;
        }
        $keyState = $state;
        foreach ($keyState as &$g) {
            ksort($g["targets"], SORT_STRING);
        }
        unset($g);
        $key = hash("sha256", json_encode([$index, $keyState], JSON_THROW_ON_ERROR));
        if (isset($this->failed[$key])) {
            return null;
        }
        $course = $this->problem->courses[$index];
        foreach ($course["choices"] as $choice) {
            $this->spend();
            $g = $choice["group_id"];
            $target = $choice["target_course_id"];
            if (isset($state[$g]["targets"][$target]) || $this->met($this->groups[$g], $state[$g])) {
                continue;
            }
            $next = $state;
            $next[$g]["targets"][$target] = true;
            // Surplus cannot affect feasibility; actual awarded amounts remain in the returned evidence.
            $next[$g]["credits"] = min(
                $this->groups[$g]["min_credits_milli"],
                $state[$g]["credits"] + min($this->groups[$g]["min_credits_milli"], $choice["credits_milli"]),
            );
            $assigned = $placements;
            $assigned[] = ["course_id" => $course["course_id"]] + $choice;
            $found = $this->search($index + 1, $next, $assigned);
            if ($found !== null) {
                return $found;
            }
        }
        $found = $this->search($index + 1, $state, $placements);
        if ($found === null) {
            $this->failed[$key] = true;
        }
        return $found;
    }

    /** @param array<string,mixed> $group
     * @param array{credits:int,targets:array<string,true>} $state
     */
    private function met(array $group, array $state): bool
    {
        $this->spend();
        if (
            $state["credits"] < $group["min_credits_milli"] ||
            count($state["targets"]) < $group["min_courses"]
        ) {
            return false;
        }
        foreach ($group["rules"] as $rule) {
            $this->spend();
            if ($rule["mode"] === "pool") {
                continue;
            }
            $covered = 0;
            foreach ($rule["course_ids"] as $target) {
                $this->spend();
                if (isset($state["targets"][$target])) {
                    $covered++;
                } elseif ($rule["mode"] === "all" && !isset($group["waived"][$target])) {
                    return false;
                }
            }
            if ($rule["mode"] === "choose" && $covered < $rule["minimum_courses"]) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,array{credits:int,targets:array<string,true>}> $state */
    private function possible(int $index, array $state): bool
    {
        foreach ($this->groups as $id => $group) {
            $this->spend();
            if (
                $state[$id]["credits"] + $this->remainingCredits[$index][$id] < $group["min_credits_milli"] ||
                count($state[$id]["targets"]) + $this->remainingCourses[$index][$id] < $group["min_courses"]
            ) {
                return false;
            }
            foreach ($group["rules"] as $rule) {
                $this->spend();
                if ($rule["mode"] === "pool") {
                    continue;
                }
                $possible = 0;
                foreach ($rule["course_ids"] as $target) {
                    $this->spend();
                    $available =
                    isset($state[$id]["targets"][$target]) || ($this->last[$id][$target] ?? -1) >= $index;
                    if ($available) {
                        $possible++;
                    } elseif ($rule["mode"] === "all" && !isset($group["waived"][$target])) {
                        return false;
                    }
                }
                if ($rule["mode"] === "choose" && $possible < $rule["minimum_courses"]) {
                    return false;
                }
            }
        }
        return true;
    }

    private function spend(): void
    {
        if ($this->steps >= $this->maximumSteps) {
            throw new AllocationBudgetExhaustedException();
        }
        $this->steps++;
    }
}
