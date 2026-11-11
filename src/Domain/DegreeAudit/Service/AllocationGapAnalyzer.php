<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AllocationProblem;

/** Necessary bounds only: eligible evidence is not an assigned or achievable placement. */
final readonly class AllocationGapAnalyzer
{
    /** @return array<string,array<string,mixed>> Reports keyed by group identity. */
    public function analyze(AllocationProblem $problem): array
    {
        $resources = [];
        $targets = [];
        foreach ($problem->courses as $course) {
            foreach ($course["choices"] as $choice) {
                $g = $choice["group_id"];
                $c = $course["course_id"];
                $t = $choice["target_course_id"];
                $credits = $choice["credits_milli"];
                $resources[$g][$c] = max($resources[$g][$c] ?? 0, $credits);
                $targets[$g][$t] = max($targets[$g][$t] ?? 0, $credits);
            }
        }
        $waived = array_fill_keys($problem->waivedCourseIds, true);
        $reports = [];
        foreach ($problem->groups as $group) {
            $id = $group["id"];
            $eligible = $targets[$id] ?? [];
            ksort($eligible, SORT_STRING);
            $actual = $resources[$id] ?? [];
            // One actual course and one required target can each be used only once within a group.
            // Both sums are optimistic; taking the tighter bound still never claims achievability.
            $credits = min(
                $group["min_credits_milli"],
                $this->sum($actual, $group["min_credits_milli"]),
                $this->sum($eligible, $group["min_credits_milli"]),
            );
            $courses = min(count($actual), count($eligible));
            $creditShortfall = $group["min_credits_milli"] - $credits;
            $courseShortfall = max(0, $group["min_courses"] - $courses);
            $shortfall = $creditShortfall > 0 || $courseShortfall > 0;
            $rules = [];
            foreach ($group["rules"] as $rule) {
                $required = $rule["mode"] === "choose" ? $rule["minimum_courses"] : 0;
                $available = 0;
                foreach ($rule["course_ids"] as $target) {
                    if ($rule["mode"] === "all") {
                        if (isset($waived[$target])) {
                            continue;
                        }
                        $required++;
                    }
                    if (isset($eligible[$target])) {
                        $available++;
                    }
                }
                $missing = max(0, $required - $available);
                $shortfall = $shortfall || $missing > 0;
                $rules[] = [
                    "id" => $rule["id"],
                    "required_courses" => $required,
                    "eligible_target_count" => $available,
                    "shortfall_courses" => $missing,
                ];
            }
            $reports[$id] = [
                "has_shortfall" => $shortfall,
                "credit_upper_bound_milli" => $credits,
                "credit_shortfall_milli" => $creditShortfall,
                "course_upper_bound" => $courses,
                "course_shortfall" => $courseShortfall,
                "eligible_target_ids" => array_keys($eligible),
                "rules" => $rules,
            ];
        }
        return $reports;
    }

    /** @param array<string,int> $amounts */
    private function sum(array $amounts, int $ceiling): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            $sum = min($ceiling, $sum + min($ceiling, $amount));
        }
        return $sum;
    }
}
