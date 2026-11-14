<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;

/** Necessary bounds only: eligible evidence is not an assigned or achievable placement. */
final readonly class AllocationGapAnalyzer
{
    /** @return array<string,array<string,mixed>> Reports keyed by group identity. */
    public function analyze(AllocationProblem $problem): array
    {
        $resources = [];
        $targets = [];
        $choicesByGroup = [];
        foreach ($problem->courses as $course) {
            foreach ($course["choices"] as $choice) {
                $g = $choice["group_id"];
                $c = $course["course_id"];
                $t = $choice["target_course_id"];
                $credits = $choice["credits_milli"];
                $resources[$g][$c] = max($resources[$g][$c] ?? 0, $credits);
                $targets[$g][$t] = max($targets[$g][$t] ?? 0, $credits);
                $choicesByGroup[$g][] = [$c, $choice];
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
                // Build one card's bounds at a time; never multiply the full evidence graph by every card.
                $ruleTargets = [];
                $actualListed = [];
                $allowed = array_fill_keys($rule['course_ids'], true);
                foreach ($choicesByGroup[$id] ?? [] as [$course, $choice]) {
                    $target = $choice['target_course_id'];
                    if (
                        !isset($allowed[$target]) ||
                        (isset($choice['rule_credits_milli']) && !isset($choice['rule_credits_milli'][$rule['id']]))
                    ) {
                        continue;
                    }
                    $amount = $choice['rule_credits_milli'][$rule['id']] ?? $choice['credits_milli'];
                    $ruleTargets[$target] = max($ruleTargets[$target] ?? 0, $amount);
                    $actualListed[$course] = max($actualListed[$course] ?? 0, $amount);
                }
                ksort($ruleTargets, SORT_STRING);
                ksort($actualListed, SORT_STRING);
                $required = $rule["mode"] === "choose" ? $rule["minimum_courses"] : 0;
                $available = 0;
                foreach ($rule["course_ids"] as $target) {
                    if ($rule["mode"] === "all") {
                        if (isset($waived[$target])) {
                            continue;
                        }
                        $required++;
                    }
                    if (isset($ruleTargets[$target])) {
                        $available++;
                    }
                }
                $courseUpper = min($available, count($actualListed));
                $missing = max(0, $required - $courseUpper);
                $shortfall = $shortfall || $missing > 0;
                $report = [
                    "id" => $rule["id"],
                    "required_courses" => $required,
                    "eligible_target_count" => $available,
                    "course_upper_bound" => $courseUpper,
                    "shortfall_courses" => $missing,
                    "eligible_target_ids" => array_keys($ruleTargets),
                    "eligible_course_ids" => array_keys($actualListed),
                ];
                if ($rule["mode"] === "credits") {
                    $requiredCredits = $rule["minimum_credits_milli"] ??
                    throw new \InvalidArgumentException("A credit rule needs its retained minimum.");
                    $upper = min(
                        $this->sum($ruleTargets, $requiredCredits),
                        $this->sum($actualListed, $requiredCredits),
                    );
                    $report += [
                        "required_credits_milli" => $requiredCredits,
                        "credit_upper_bound_milli" => $upper,
                        "credit_shortfall_milli" => $requiredCredits - $upper,
                    ];
                    $shortfall = $shortfall || $upper < $requiredCredits;
                }
                $rules[] = $report;
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

    /**
     * Proven cross-group shortages only; absence is not proof that a placement exists.
     * Examine overlapping pairs and complete connected components, never all subsets.
     * @return array{conflicts:list<array<string,mixed>>,more_conflicts:bool}
     */
    public function competition(AllocationProblem $problem): array
    {
        $result = ["conflicts" => [], "more_conflicts" => false];
        if ($problem->sharing !== null || $problem->reuse !== CourseGroupReuse::OneGroup) {
            return $result;
        }
        $individual = $this->analyze($problem);
        $waived = array_fill_keys($problem->waivedCourseIds, true);
        $demands = [];
        foreach ($problem->groups as $group) {
            $all = [];
            $credits = $group["min_credits_milli"];
            $count = $group["min_courses"];
            foreach ($group["rules"] as $rule) {
                if ($rule["mode"] === "all") {
                    foreach ($rule["course_ids"] as $target) {
                        if (!isset($waived[$target])) {
                            $all[$target] = true;
                        }
                    }
                } elseif ($rule["mode"] === "choose") {
                    // Overlapping rules can share a placement within a group: never add their counts.
                    $count = max($count, $rule["minimum_courses"]);
                } elseif ($rule["mode"] === "credits") {
                    // Credit cards may overlap; adding their minimums could invent a shortage.
                    $credits = max($credits, $rule["minimum_credits_milli"] ??
                        throw new \InvalidArgumentException("A credit rule needs its retained minimum."));
                }
            }
            $count = max($count, count($all), $credits > 0 ? 1 : 0);
            if ($count > 0 && !$individual[$group["id"]]["has_shortfall"]) {
                $demands[$group["id"]] = ["credits" => $credits, "courses" => $count];
            }
        }
        ksort($demands, SORT_STRING);
        $resources = [];
        foreach ($problem->courses as $course) {
            foreach ($course["choices"] as $choice) {
                if (isset($demands[$choice["group_id"]])) {
                    $g = $choice["group_id"];
                    $c = $course["course_id"];
                    $resources[$g][$c] = max($resources[$g][$c] ?? 0, $choice["credits_milli"]);
                }
            }
        }
        $ids = array_keys($demands);
        $edges = [];
        $sets = [];
        foreach ($ids as $i => $a) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $b = $ids[$j];
                if (array_intersect_key($resources[$a] ?? [], $resources[$b] ?? []) !== []) {
                    $edges[$a][$b] = true;
                    $edges[$b][$a] = true;
                    $sets[] = [$a, $b];
                }
            }
        }
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $component = [];
            $pending = [$id];
            while ($pending !== []) {
                $at = array_pop($pending);
                if (isset($seen[$at])) {
                    continue;
                }
                $seen[$at] = true;
                $component[] = $at;
                foreach (array_keys($edges[$at] ?? []) as $neighbor) {
                    if (!isset($seen[$neighbor])) {
                        $pending[] = $neighbor;
                    }
                }
            }
            if (count($component) > 2) {
                sort($component, SORT_STRING);
                $sets[] = $component;
            }
        }
        usort(
            $sets,
            static fn(array $a, array $b): int => count($a) <=> count($b) ?:
            strcmp(implode(":", $a), implode(":", $b)),
        );
        foreach ($sets as $set) {
            // A smaller explanation already demonstrates this larger set cannot be satisfied.
            foreach ($result["conflicts"] as $prior) {
                if (array_diff($prior["group_ids"], $set) === []) {
                    continue 2;
                }
            }
            $credits = $count = 0;
            $available = [];
            foreach ($set as $id) {
                $credits += $demands[$id]["credits"];
                $count += $demands[$id]["courses"];
                foreach ($resources[$id] ?? [] as $course => $amount) {
                    // An actual course contributes once across the set, at its most optimistic eligible amount.
                    $available[$course] = max($available[$course] ?? 0, $amount);
                }
            }
            $upper = $this->sum($available, $credits);
            $creditGap = $credits - $upper;
            $courseGap = max(0, $count - count($available));
            if ($creditGap === 0 && $courseGap === 0) {
                continue;
            }
            if (count($result["conflicts"]) === 10) {
                $result["more_conflicts"] = true;
                break;
            }
            $result["conflicts"][] = [
                "group_ids" => $set,
                "required_credits_milli" => $credits,
                "credit_upper_bound_milli" => $upper,
                "credit_shortfall_milli" => $creditGap,
                "required_courses" => $count,
                "course_upper_bound" => count($available),
                "course_shortfall" => $courseGap,
            ];
        }
        return $result;
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
