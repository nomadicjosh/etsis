<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\DegreeAudit\ValueObject\AllocationOutcome;
use Domain\DegreeAudit\ValueObject\AllocationProblem;
use Domain\DegreeAudit\ValueObject\AllocationResult;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use InvalidArgumentException;

/** Describe an established plan, without searching again or treating optimistic bounds as applied work. */
final readonly class AllocationCoverageReview
{
    /** @return array<string,array<string,mixed>> */
    public function review(
        #[\SensitiveParameter] AllocationProblem $problem,
        #[\SensitiveParameter] AllocationResult $result,
    ): array {
        if ($result->outcome !== AllocationOutcome::Feasible) {
            return [];
        }
        if (count($result->placements) > 5000) {
            self::invalid();
        }
        $allowed = [];
        foreach ($problem->courses as $course) {
            foreach ($course["choices"] as $choice) {
                $allowed[$course["course_id"]][$this->key($choice)] = $choice;
            }
        }
        $waived = array_fill_keys($problem->waivedCourseIds, true);
        $totals = $targets = $resources = $contributions = [];
        foreach ($result->placements as $placement) {
            $course = $placement["course_id"];
            $group = $placement["group_id"];
            $target = $placement["target_course_id"];
            $choice = $allowed[$course][$this->key($placement)] ?? null;
            $work = $placement["work_ids"];
            sort($work, SORT_STRING);
            if (
                $choice === null ||
                count($placement) !== count($choice) + 1 ||
                $placement["credits_milli"] !== $choice["credits_milli"] ||
                $work !== $choice["work_ids"] ||
                isset($targets[$group][$target]) ||
                isset($resources[$group][$course]) ||
                ($problem->reuse === CourseGroupReuse::OneGroup && isset($resources["all"][$course]))
            ) {
                self::invalid();
            }
            $targets[$group][$target] = true;
            $resources[$group][$course] = $resources["all"][$course] = true;
            $totals[$group] = self::add($totals[$group] ?? 0, $choice["credits_milli"]);
            foreach ($problem->ruleCredits($choice) as $rule => $credits) {
                $amount = $contributions[$group][$rule] ?? ["credits" => 0, "courses" => 0, "unwaived" => 0];
                $amount["credits"] = self::add($amount["credits"], $credits);
                $amount["courses"]++;
                $amount["unwaived"] += isset($waived[$target]) ? 0 : 1;
                $contributions[$group][$rule] = $amount;
            }
        }
        $coverage = [];
        foreach ($problem->groups as $group) {
            $id = $group["id"];
            $credits = $totals[$id] ?? 0;
            $count = count($targets[$id] ?? []);
            if ($credits < $group["min_credits_milli"] || $count < $group["min_courses"]) {
                self::invalid();
            }
            $rules = [];
            foreach ($group["rules"] as $rule) {
                $applied = $contributions[$id][$rule["id"]] ?? [
                    "credits" => 0,
                    "courses" => 0,
                    "unwaived" => 0,
                ];
                $required =
                $rule["mode"] === "all"
                ? array_diff_key(array_fill_keys($rule["course_ids"], true), $waived)
                : [];
                $covered = $rule["mode"] === "all" ? $applied["unwaived"] : $applied["courses"];
                $needed = match ($rule["mode"]) {
                    "all" => count($required),
                    "choose" => $rule["minimum_courses"],
                    default => null,
                };
                $requiredCredits = $rule["minimum_credits_milli"] ?? null;
                $appliedCredits = $applied["credits"];
                if (
                    ($needed !== null && $covered < $needed) ||
                    ($requiredCredits !== null && $appliedCredits < $requiredCredits)
                ) {
                    self::invalid();
                }
                $rules[$rule["id"]] = [
                    "mode" => $rule["mode"],
                    "applied_courses" => $applied["courses"],
                    "applied_credits_milli" => $appliedCredits,
                    "covered_courses" => $covered,
                    "required_courses" => $needed,
                    "required_credits_milli" => $requiredCredits,
                    "waived_courses" =>
                        $rule["mode"] === "all" ? count($rule["course_ids"]) - count($required) : 0,
                ];
            }
            $coverage[$id] = [
                "applied_credits_milli" => $credits,
                "applied_courses" => $count,
                "rules" => $rules,
            ];
        }
        return $coverage;
    }

    /** @param array<string,mixed> $choice */
    private function key(#[\SensitiveParameter] array $choice): string
    {
        $amounts = $choice["rule_credits_milli"] ?? null;
        if ($amounts !== null) {
            ksort($amounts, SORT_STRING);
        }
        return $choice["group_id"] .
        ":" .
        $choice["target_course_id"] .
        ":" .
        hash("sha256", json_encode($amounts, JSON_THROW_ON_ERROR));
    }

    private static function add(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new \Domain\DegreeAudit\Exception\AllocationBudgetExhaustedException();
        }
        return $left + $right;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "The proposed placement does not match its reviewed requirements.",
        );
    }
}
